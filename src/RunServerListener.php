<?php

/**
 * SPDX-FileCopyrightText: 2022 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace PhpBuiltin;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTeardown;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use PhpBuiltin\Exception\ServerException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class RunServerListener implements EventSubscriberInterface
{
    private string $pid = '0';
    private static string $host;
    private static int $port = 0;
    private ?int $verbose = null;
    private string $rootDir;
    private string $runAs = '';
    private int $workers = 0;
    private static self $instance;
    private ?string $serverLogFile = null;
    private ?string $serverExitFile = null;
    private ?string $serverPidFile = null;
    private ?string $serverWrapperFile = null;
    /** @var list<string> */
    private array $diagnosticMessages = [];

    public function __construct(?int $verbose, string $rootDir, string $host, string $runAs, int $workers)
    {
        $this->verbose = $verbose;
        $this->rootDir = $rootDir;
        $this->runAs = $runAs;
        $this->workers = $workers;
        self::$host = $host;
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    #[\Override]
    public static function getSubscribedEvents()
    {
        return array(
            BeforeSuiteTested::BEFORE => 'beforeSuite',
            BeforeSuiteTeardown::AFTER => 'afterSuite'
        );
    }

    public function beforeSuite(BeforeSuiteTested $event): void
    {
        $this->start();
    }

    public function start(): void
    {
        $this->killZombies();
        if ($this->isRunning()) {
            return;
        }

        if (self::$port == 0) {
            self::$port = $this->findOpenPort();
        }

        $script = escapeshellarg($this->rootDir);
        $php = PHP_BINARY !== '' && is_file(PHP_BINARY) ? escapeshellarg(PHP_BINARY) : 'php';

        $cmd = $php . ' -S ' . self::$host . ':' . self::$port . ' -t ' . $script;

        if ($this->runAs && get_current_user() !== $this->runAs) {
            if (!$this->isSuperUser()) {
                throw new ServerException(
                    sprintf(
                        <<<ERROR
                        File: %s
                        Owner: %s
                        Need to be: %s
                        Suggested command to fix: chown -R %s: .
                        ERROR,
                        __FILE__,
                        get_current_user(),
                        $this->runAs,
                        $this->runAs
                    )
                );
            }
            $cmd = 'runuser -u ' . $this->runAs . ' -- ' . $cmd;
        }

        if ($this->workers > 0) {
            $cmd = 'PHP_CLI_SERVER_WORKERS=' . $this->workers . ' ' . $cmd;
        }

        if ($this->isVerbose()) {
            $this->prepareDiagnosticFiles();
            $serverLogFile = $this->serverLogFile;
            $serverPidFile = $this->serverPidFile;
            $serverExitFile = $this->serverExitFile;
            $serverWrapperFile = $this->serverWrapperFile;
            if ($serverLogFile === null || $serverPidFile === null || $serverExitFile === null || $serverWrapperFile === null) {
                throw new ServerException('Unable to create temporary log file for PHP built-in server');
            }
            $this->writeVerboseServerWrapper(
                $cmd,
                $serverLogFile,
                $serverPidFile,
                $serverExitFile,
                $serverWrapperFile
            );
            // Do not wrap this start with sudo: on GitHub Actions, `sudo nohup sh /tmp/...`
            // fails silently (outer stdout/stderr discarded) and never writes pid/log/exit.
            // Privileged start is only required when runAs is configured.
            $fullCmd = sprintf(
                'nohup sh %s >>%s 2>&1 & echo $!',
                escapeshellarg($serverWrapperFile),
                escapeshellarg($serverLogFile)
            );
            if ($this->runAs !== '') {
                $fullCmd = $this->parseCommand($fullCmd);
            }
        } else {
            $fullCmd = $this->parseCommand(sprintf(
                '%s > /dev/null 2>&1 & echo $!',
                escapeshellcmd($cmd)
            ));
        }

        $wrapperPid = (string)(int) exec($fullCmd);
        if ($this->isVerbose()) {
            $this->pid = $this->readPhpServerPid($wrapperPid);
        } else {
            $this->pid = $wrapperPid;
        }

        if (!$this->pid) {
            throw new ServerException('Error starting server, received ' . $this->pid . ', expected int PID');
        }

        for ($i = 0; $i <= 20; $i++) {
            usleep(100000);

            $open = @fsockopen(self::$host, self::$port);
            if (is_resource($open)) {
                fclose($open);
                break;
            }
        }

        if (!$this->isRunning()) {
            if ($this->isVerbose()) {
                $this->waitForExitFile(40);
                $this->reportExitStatus();
                $this->flushServerOutput();
            }
            $details = implode("\n", $this->diagnosticMessages);
            throw new ServerException(
                'Failed to start server. Is something already running on port ' . self::$port . "?\n" .
                'Full command: ' . $fullCmd .
                ($details !== '' ? "\n" . $details : '')
            );
        }

        if ($this->isVerbose()) {
            $this->writeDiagnostic(sprintf(
                'Started PHP built-in server pid=%s host=%s port=%d workers=%d log=%s',
                $this->pid,
                self::$host,
                self::$port,
                $this->workers,
                $this->serverLogFile ?? '(none)'
            ));
        }

        register_shutdown_function(function () {
            if ($this->pid !== '0') {
                $this->stop();
            }
        });
    }

    private function isSuperUser(): bool
    {
        if (posix_getuid() === 0) {
            return true;
        }
        $groups = posix_getgroups();
        if ($groups === false) {
            return false;
        }
        foreach ($groups as $group) {
            if ($group == 'sudo' || $group == 'wheel') {
                return true;
            }
        }
        return false;
    }

    /**
     * Is the Web Server currently running?
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        if (!$this->pid) {
            return false;
        }

        exec(sprintf('ps %d', $this->pid), $result);

        return count($result) > 1;
    }

    /**
     * Stop the Web Server
     */
    public function stop(): void
    {
        $trackedPid = $this->pid;

        if ($this->isVerbose() && $trackedPid !== '0' && !$this->isRunning()) {
            $this->writeDiagnostic(sprintf(
                'Teardown: server process already gone (pid was %s).',
                $trackedPid
            ));
            $this->waitForExitFile(40);
            $this->reportExitStatus();
            $this->flushServerOutput();
            $this->killZombies();
            $this->pid = '0';
            $this->cleanupDiagnosticFiles();
            return;
        }

        if ($this->pid && $this->isRunning()) {
            if ($this->isVerbose()) {
                $this->writeDiagnostic(sprintf('Stopping PHP built-in server pid=%s', $this->pid));
            }
            exec($this->parseCommand('kill ' . $this->pid));
            $this->waitForProcessExit(40);
            $this->waitForExitFile(40);
        }

        if ($this->isVerbose()) {
            $this->reportExitStatus();
            $this->flushServerOutput();
        }

        $this->killZombies();

        $this->pid = '0';
        $this->cleanupDiagnosticFiles();
    }

    public function killZombies(): void
    {
        $cmd = 'ps -eo pid,command|' .
            'grep "php -S ' . self::$host . '"|' .
            'grep -v grep|' .
            'sed -e "s/^[[:space:]]*//"|cut -d" " -f1';
        $output = shell_exec($cmd);
        if (!is_string($output)) {
            return;
        }
        $pids = trim($output);
        $pids = explode("\n", $pids);
        foreach ($pids as $pid) {
            if ($pid && (!$this->pid || $pid !== $this->pid)) {
                if ($this->isProcessAlive($pid)) {
                    exec($this->parseCommand('kill ' . $pid));
                }
            }
        }
    }

    /**
     * Parse command
     *
     * Have commands that need to be executed as sudo otherwise don't will work,
     * by example the command runuser or kill. To prevent error when run in a
     * GitHub Actions, these commands are executed prefixed by sudo when exists
     * an environment called GITHUB_ACTIONS.
     */
    private function parseCommand(string $command): string
    {
        if (getenv('GITHUB_ACTIONS') !== false) {
            if ($this->runAs) {
                return 'sudo -u ' . $this->runAs . ' ' . $command;
            }
            $command = 'sudo ' . $command;
        }
        return $command;
    }

    /**
     * Get the HTTP root of the webserver
     *  e.g.: http://127.0.0.1:8123
     *
     * @return string
     */
    public static function getServerRoot(): string
    {
        return 'http://' . self::$host . ':' . self::$port . '/';
    }

    public static function getHost(): string
    {
        return self::$host;
    }

    /**
     * Get the port the network server is to be ran on.
     *
     * @return int
     */
    public function getPort()
    {
        return self::$port;
    }

    /**
     * @return list<string>
     */
    public function getDiagnosticMessages(): array
    {
        return $this->diagnosticMessages;
    }

    public function getServerLogFile(): ?string
    {
        return $this->serverLogFile;
    }

    public function isVerbose(): bool
    {
        return is_numeric($this->verbose);
    }

    /**
     * Let the OS find an open port for you.
     *
     * @return int
     *
     * @psalm-return int<1, max>
     */
    private function findOpenPort(): int
    {
        /** @psalm-suppress UndefinedConstant */
        $sock = socket_create(AF_INET, SOCK_STREAM, 0);
        if ($sock === false) {
            throw new ServerException('Could not create socket');
        }

        // Bind the socket to an address/port
        if (!socket_bind($sock, self::$host, 0)) {
            socket_close($sock);
            throw new ServerException('Could not bind to address');
        }

        socket_getsockname($sock, $checkAddress, $checkPort);
        socket_close($sock);

        if ($checkPort > 0) {
            return $checkPort;
        }

        throw new ServerException('Failed to find open port');
    }

    public function afterSuite(AfterSuiteTested $event): void
    {
        $this->stop();
    }

    private function prepareDiagnosticFiles(): void
    {
        $this->cleanupDiagnosticFiles();
        $base = tempnam(sys_get_temp_dir(), 'behat-php-server-');
        if ($base === false) {
            throw new ServerException('Unable to create temporary log file for PHP built-in server');
        }
        $this->serverLogFile = $base . '.log';
        $this->serverExitFile = $base . '.exit';
        $this->serverPidFile = $base . '.pid';
        $this->serverWrapperFile = $base . '.sh';
        @unlink($base);
        foreach ([$this->serverLogFile, $this->serverExitFile, $this->serverPidFile] as $file) {
            touch($file);
            @chmod($file, 0666);
        }
    }

    private function cleanupDiagnosticFiles(): void
    {
        foreach ([$this->serverLogFile, $this->serverExitFile, $this->serverPidFile, $this->serverWrapperFile] as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }
        $this->serverLogFile = null;
        $this->serverExitFile = null;
        $this->serverPidFile = null;
        $this->serverWrapperFile = null;
    }

    private function writeVerboseServerWrapper(
        string $cmd,
        string $serverLogFile,
        string $serverPidFile,
        string $serverExitFile,
        string $wrapperFile,
    ): void {
        $pathPrefix = '';
        if (PHP_BINARY !== '' && is_file(PHP_BINARY)) {
            $pathPrefix = sprintf("PATH=%s:\"\$PATH\"\nexport PATH\n", escapeshellarg(dirname(PHP_BINARY)));
        }

        $script = $pathPrefix . sprintf(
            "echo wrapper-start > %s\n" .
            "%s >> %s 2>&1 &\n" .
            "echo \$! > %s\n" .
            "wait \$(cat %s)\n" .
            "echo \$? > %s\n",
            escapeshellarg($serverLogFile),
            $cmd,
            escapeshellarg($serverLogFile),
            escapeshellarg($serverPidFile),
            escapeshellarg($serverPidFile),
            escapeshellarg($serverExitFile)
        );

        if (file_put_contents($wrapperFile, $script) === false) {
            throw new ServerException('Unable to create PHP built-in server wrapper script');
        }
        @chmod($wrapperFile, 0755);
    }

    private function readPhpServerPid(string $wrapperPid): string
    {
        for ($i = 0; $i < 50; $i++) {
            if (is_string($this->serverPidFile) && is_file($this->serverPidFile)) {
                $pid = trim((string)file_get_contents($this->serverPidFile));
                if ($pid !== '' && ctype_digit($pid)) {
                    return $pid;
                }
            }
            usleep(50000);
        }

        return $wrapperPid;
    }

    private function waitForProcessExit(int $attempts): void
    {
        for ($i = 0; $i < $attempts; $i++) {
            if (!$this->isRunning()) {
                return;
            }
            usleep(50000);
        }
    }

    private function waitForExitFile(int $attempts): void
    {
        if (!is_string($this->serverExitFile)) {
            return;
        }
        for ($i = 0; $i < $attempts; $i++) {
            if (is_file($this->serverExitFile) && trim((string)file_get_contents($this->serverExitFile)) !== '') {
                return;
            }
            usleep(50000);
        }
    }

    private function isProcessAlive(string $pid): bool
    {
        if ($pid === '' || $pid === '0') {
            return false;
        }
        exec(sprintf('ps %d', $pid), $result);
        return count($result) > 1;
    }

    private function reportExitStatus(): void
    {
        if (!is_string($this->serverExitFile) || !is_file($this->serverExitFile)) {
            if (!$this->isRunning()) {
                $this->writeDiagnostic('Server exit status unavailable (process ended before status could be captured).');
            }
            return;
        }

        $status = trim((string)file_get_contents($this->serverExitFile));
        if ($status === '') {
            $this->writeDiagnostic('Server exit status file was empty.');
            return;
        }

        $this->writeDiagnostic(sprintf('Server process exit status: %s', $this->formatExitStatus($status)));
    }

    private function formatExitStatus(string $status): string
    {
        if (!ctype_digit($status)) {
            return $status;
        }

        $code = (int)$status;
        $signalName = $this->signalNameFromWaitStatus($code);
        if ($signalName === null) {
            return (string)$code;
        }

        return sprintf('%d (possibly %s)', $code, $signalName);
    }

    private function signalNameFromWaitStatus(int $code): ?string
    {
        if ($code < 128) {
            return null;
        }

        return match ($code - 128) {
            9 => 'SIGKILL',
            11 => 'SIGSEGV',
            15 => 'SIGTERM',
            default => sprintf('signal %d', $code - 128),
        };
    }

    private function flushServerOutput(): void
    {
        if (!is_string($this->serverLogFile) || !is_file($this->serverLogFile)) {
            $this->writeDiagnostic('Server stdout/stderr log unavailable.');
            return;
        }

        $output = @file_get_contents($this->serverLogFile);
        if ($output === false) {
            $this->writeDiagnostic(sprintf(
                'Server stdout/stderr log unreadable: %s',
                $this->serverLogFile
            ));
            return;
        }
        if ($output === '') {
            $this->writeDiagnostic('Server stdout/stderr: (empty)');
            return;
        }

        $this->writeDiagnostic("Server stdout/stderr:\n" . rtrim($output));
    }

    private function writeDiagnostic(string $message): void
    {
        $this->diagnosticMessages[] = $message;
        if (!$this->isVerbose()) {
            return;
        }
        fwrite(STDERR, '[php-builtin-server] ' . $message . "\n");
    }
}
