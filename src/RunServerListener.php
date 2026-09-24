<?php

/**
 * SPDX-FileCopyrightText: 2022 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace PhpBuiltin;

use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
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
    private string $processGroupId = '0';
    private bool $unexpectedServerFailure = false;
    private int $observedWorkerCount = 0;
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
            BeforeScenarioTested::BEFORE => 'beforeScenario',
            AfterScenarioTested::AFTER => 'afterScenario',
            BeforeSuiteTeardown::AFTER => 'afterSuite'
        );
    }

    public function beforeSuite(BeforeSuiteTested $event): void
    {
        $this->start();
    }

    public function beforeScenario(BeforeScenarioTested $event): void
    {
        $this->assertServerHealthy('before scenario');
    }

    public function afterScenario(AfterScenarioTested $event): void
    {
        $this->assertServerHealthy('after scenario');
    }

    /**
     * Fail as an infrastructure error as soon as the server is no longer usable.
     *
     * This deliberately does not restart the server: a request may have changed
     * application state before PHP terminated, so continuing with a fresh server
     * could hide the original failure and make following scenarios unreliable.
     */
    public function assertServerHealthy(string $checkpoint = 'health check'): void
    {
        if ($this->pid === '0') {
            return;
        }

        $processAlive = $this->isRunning();
        $portReachable = $this->isServerPortInUse();
        $liveWorkerCount = $processAlive && $this->workers > 0
            ? count($this->collectDescendantPids($this->pid))
            : 0;
        $workersHealthy = !$this->isVerbose()
            || $this->observedWorkerCount === 0
            || $liveWorkerCount >= $this->observedWorkerCount;

        if ($processAlive && $portReachable && $workersHealthy) {
            return;
        }

        $this->unexpectedServerFailure = true;
        $this->captureFailureSnapshot(
            $checkpoint,
            $processAlive,
            $portReachable,
            $liveWorkerCount
        );
        $this->waitForExitFile(40);
        $this->reportExitStatus();
        $this->flushServerOutput();

        throw new ServerException(sprintf(
            'PHP built-in server became unhealthy during %s (pid=%s, process=%s, port=%s, workers=%d/%d).',
            $checkpoint,
            $this->pid,
            $processAlive ? 'alive' : 'gone',
            $portReachable ? 'reachable' : 'unreachable',
            $liveWorkerCount,
            $this->observedWorkerCount
        ));
    }

    public function start(): void
    {
        $this->unexpectedServerFailure = false;
        $this->observedWorkerCount = 0;
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
        $switchUser = $this->shouldSwitchUser();

        if ($switchUser) {
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
            // Already root: switch with runuser. Otherwise wrap the final start command with sudo -u.
            if (posix_getuid() === 0) {
                $cmd = 'runuser -u ' . $this->runAs . ' -- ' . $cmd;
                $switchUser = false;
            }
        }

        if ($this->workers > 0) {
            $cmd = 'env PHP_CLI_SERVER_WORKERS=' . $this->workers . ' ' . $cmd;
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
            $fullCmd = sprintf(
                'nohup sh %s >>%s 2>&1 & echo $!',
                escapeshellarg($serverWrapperFile),
                escapeshellarg($serverLogFile)
            );
        } else {
            $fullCmd = sprintf(
                '%s > /dev/null 2>&1 & echo $!',
                $this->wrapInNewSession(escapeshellcmd($cmd))
            );
        }

        if ($switchUser) {
            $fullCmd = $this->withPrivilege($fullCmd);
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

        $this->processGroupId = $this->resolveProcessGroupId($this->pid);

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

        if ($this->workers > 0) {
            for ($i = 0; $i < 20; $i++) {
                $this->observedWorkerCount = count($this->collectDescendantPids($this->pid));
                if ($this->observedWorkerCount >= $this->workers) {
                    break;
                }
                usleep(50000);
            }
        }

        if ($this->isVerbose()) {
            $this->writeDiagnostic(sprintf(
                'Started PHP built-in server pid=%s host=%s port=%d workers=%d observed_workers=%d log=%s',
                $this->pid,
                self::$host,
                self::$port,
                $this->workers,
                $this->observedWorkerCount,
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
            $this->terminateServerTree($trackedPid);
            $this->waitForExitFile(40);
            $this->reportExitStatus();
            $this->flushServerOutput();
            $this->pid = '0';
            $this->processGroupId = '0';
            $this->observedWorkerCount = 0;
            if (!$this->unexpectedServerFailure) {
                $this->cleanupDiagnosticFiles();
            }
            return;
        }

        if ($this->pid !== '0') {
            if ($this->isVerbose() && $this->isRunning()) {
                $this->writeDiagnostic(sprintf('Stopping PHP built-in server pid=%s', $this->pid));
            }
            $this->terminateServerTree($this->pid);
            $this->waitForExitFile(40);
        }

        if ($this->isVerbose()) {
            $this->reportExitStatus();
            $this->flushServerOutput();
        }

        $this->pid = '0';
        $this->processGroupId = '0';
        $this->observedWorkerCount = 0;
        if (!$this->unexpectedServerFailure) {
            $this->cleanupDiagnosticFiles();
        }
    }

    /**
     * Capture process and listener state at the first moment a server failure is observed.
     */
    private function captureFailureSnapshot(
        string $checkpoint,
        bool $processAlive,
        bool $portReachable,
        int $liveWorkerCount,
    ): void {
        if (!$this->isVerbose()) {
            return;
        }

        $this->writeDiagnostic(sprintf(
            'SERVER FAILURE DETECTED checkpoint=%s pid=%s pgid=%s process=%s port=%s host=%s:%d workers=%d/%d',
            $checkpoint,
            $this->pid,
            $this->processGroupId,
            $processAlive ? 'alive' : 'gone',
            $portReachable ? 'reachable' : 'unreachable',
            self::$host,
            self::$port,
            $liveWorkerCount,
            $this->observedWorkerCount
        ));

        $commands = [
            'server process group' => sprintf(
                'ps -o pid,ppid,pgid,sid,user,stat,etime,rss,vsz,pcpu,pmem,args --forest -g %s 2>&1',
                escapeshellarg($this->processGroupId)
            ),
            'listener state' => sprintf('lsof -nP -iTCP:%d -sTCP:LISTEN 2>&1', self::$port),
            'memory' => 'free -m 2>&1',
            'core limit' => 'sh -c "ulimit -c" 2>&1',
            'core pattern' => 'cat /proc/sys/kernel/core_pattern 2>&1',
        ];

        foreach ($commands as $label => $command) {
            $output = [];
            exec($command, $output, $exitCode);
            $this->writeDiagnostic(sprintf(
                "%s (exit=%d):\n%s",
                $label,
                $exitCode,
                $output === [] ? '(empty)' : implode("\n", $output)
            ));
        }
    }

    /**
     * Terminate leftover server processes for the configured host/port.
     * Prefer process-group / descendant cleanup over matching executable names.
     */
    public function killZombies(): void
    {
        if ($this->pid !== '0') {
            $this->terminateServerTree($this->pid);
            return;
        }

        if ($this->processGroupId !== '0') {
            $this->signalProcessGroup($this->processGroupId, 'TERM');
            $this->signalProcessGroup($this->processGroupId, 'KILL');
            $this->processGroupId = '0';
        }

        foreach ($this->findListenerPids(self::$port) as $pid) {
            $this->signalProcess($pid, 'TERM');
            $this->signalProcess($pid, 'KILL');
        }
    }

    private function shouldSwitchUser(): bool
    {
        return $this->runAs !== '' && get_current_user() !== $this->runAs;
    }

    /**
     * Prefix a command with sudo only when privilege is required to switch user.
     * GITHUB_ACTIONS alone never implies sudo.
     */
    private function withPrivilege(string $command): string
    {
        if ($this->runAs !== '') {
            return 'sudo -u ' . $this->runAs . ' ' . $command;
        }

        return 'sudo ' . $command;
    }

    /**
     * Start the PHP server in a new session when possible so workers share a
     * dedicated process group that stop() can terminate as a unit.
     */
    private function wrapInNewSession(string $command): string
    {
        if ($this->canCreateNewSession()) {
            return 'setsid ' . $command;
        }

        return $command;
    }

    private function canCreateNewSession(): bool
    {
        exec('command -v setsid', $output, $exitCode);

        return $exitCode === 0;
    }

    private function resolveProcessGroupId(string $pid): string
    {
        if ($pid === '' || $pid === '0' || !ctype_digit($pid)) {
            return '0';
        }

        $output = [];
        exec(sprintf('ps -o pgid= -p %s', $pid), $output, $exitCode);
        if ($exitCode !== 0 || !isset($output[0])) {
            return $pid;
        }

        $pgid = trim((string) $output[0]);

        return ctype_digit($pgid) ? $pgid : $pid;
    }

    /**
     * Stop the tracked server PID, its process group, and any remaining children.
     */
    private function terminateServerTree(string $pid): void
    {
        if ($pid === '' || $pid === '0') {
            return;
        }

        $pgid = $this->processGroupId !== '0' ? $this->processGroupId : $this->resolveProcessGroupId($pid);
        $descendants = $this->collectDescendantPids($pid);

        if ($pgid !== '0') {
            $this->signalProcessGroup($pgid, 'TERM');
        }
        $this->signalProcess($pid, 'TERM');
        foreach ($descendants as $childPid) {
            $this->signalProcess($childPid, 'TERM');
        }

        $this->waitForTreeExit($pid, $descendants, 40);

        if ($pgid !== '0') {
            $this->signalProcessGroup($pgid, 'KILL');
        }
        foreach (array_merge([$pid], $descendants) as $targetPid) {
            if ($this->isProcessAlive($targetPid)) {
                $this->signalProcess($targetPid, 'KILL');
            }
        }

        foreach ($this->findListenerPids(self::$port) as $listenerPid) {
            $this->signalProcess($listenerPid, 'KILL');
        }

        $this->waitForPortRelease(40);
    }

    /**
     * @return list<string>
     */
    private function collectDescendantPids(string $rootPid): array
    {
        if ($rootPid === '' || $rootPid === '0' || !ctype_digit($rootPid)) {
            return [];
        }

        $found = [];
        $queue = [$rootPid];
        while ($queue !== []) {
            $parent = array_shift($queue);
            $output = [];
            exec(sprintf('pgrep -P %s', $parent), $output, $exitCode);
            if ($exitCode !== 0) {
                continue;
            }
            foreach ($output as $line) {
                $child = trim($line);
                if ($child === '' || !ctype_digit($child) || isset($found[$child])) {
                    continue;
                }
                $found[$child] = true;
                $queue[] = $child;
            }
        }

        return array_keys($found);
    }

    /**
     * @param list<string> $descendants
     */
    private function waitForTreeExit(string $rootPid, array $descendants, int $attempts): void
    {
        for ($i = 0; $i < $attempts; $i++) {
            $alive = $this->isProcessAlive($rootPid);
            if (!$alive) {
                foreach ($descendants as $childPid) {
                    if ($this->isProcessAlive($childPid)) {
                        $alive = true;
                        break;
                    }
                }
            }
            if (!$alive) {
                return;
            }
            usleep(50000);
        }
    }

    private function waitForPortRelease(int $attempts): void
    {
        for ($i = 0; $i < $attempts; $i++) {
            if (!$this->isServerPortInUse()) {
                return;
            }
            usleep(50000);
        }
    }

    private function isServerPortInUse(): bool
    {
        if (self::$port <= 0) {
            return false;
        }

        $connection = @fsockopen(self::$host, self::$port, $errno, $errstr, 0.05);
        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return $this->findListenerPids(self::$port) !== [];
    }

    /**
     * @return list<string>
     */
    private function findListenerPids(int $port): array
    {
        if ($port <= 0) {
            return [];
        }

        $output = [];
        exec(sprintf('lsof -nP -iTCP:%d -sTCP:LISTEN -t 2>/dev/null', $port), $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }

        $pids = [];
        foreach ($output as $line) {
            $pid = trim($line);
            if ($pid !== '' && ctype_digit($pid)) {
                $pids[] = $pid;
            }
        }

        return array_values(array_unique($pids));
    }

    private function signalProcessGroup(string $pgid, string $signal): void
    {
        if ($pgid === '' || $pgid === '0' || !ctype_digit($pgid)) {
            return;
        }

        $command = sprintf('kill -s %s -- -%s', $signal, $pgid);
        if ($this->isProcessOwnedByOtherUser($pgid)) {
            $command = 'sudo ' . $command;
        }
        exec($command . ' 2>/dev/null');
    }

    private function signalProcess(string $pid, string $signal): void
    {
        if ($pid === '' || $pid === '0' || !ctype_digit($pid)) {
            return;
        }

        $command = sprintf('kill -s %s %s', $signal, $pid);
        if ($this->isProcessOwnedByOtherUser($pid)) {
            $command = 'sudo ' . $command;
        }
        exec($command . ' 2>/dev/null');
    }

    private function isProcessOwnedByOtherUser(string $pid): bool
    {
        if ($pid === '' || $pid === '0' || !ctype_digit($pid)) {
            return false;
        }

        $output = [];
        exec(sprintf('ps -o uid= -p %s', $pid), $output, $exitCode);
        if ($exitCode !== 0 || !isset($output[0])) {
            return false;
        }

        return (int) trim((string) $output[0]) !== posix_getuid();
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

    /**
     * @return array{log: ?string, exit: ?string, pid: ?string, wrapper: ?string}
     */
    public function getDiagnosticFiles(): array
    {
        return [
            'log' => $this->serverLogFile,
            'exit' => $this->serverExitFile,
            'pid' => $this->serverPidFile,
            'wrapper' => $this->serverWrapperFile,
        ];
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
            "ulimit -c unlimited 2>/dev/null || true\n" .
            "%s >> %s 2>&1 &\n" .
            "echo \$! > %s\n" .
            "wait \$(cat %s)\n" .
            "echo \$? > %s\n",
            escapeshellarg($serverLogFile),
            $this->wrapInNewSession($cmd),
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
