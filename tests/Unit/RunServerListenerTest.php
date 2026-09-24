<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace PhpBuiltin\Tests\Unit;

use PhpBuiltin\RunServerListener;
use PHPUnit\Framework\TestCase;

final class RunServerListenerTest extends TestCase
{
    private string $docRoot;

    protected function setUp(): void
    {
        $this->docRoot = sys_get_temp_dir() . '/behat-php-server-docroot-' . uniqid('', true);
        mkdir($this->docRoot);
        file_put_contents($this->docRoot . '/index.php', "<?php echo 'ok';\n");
    }

    protected function tearDown(): void
    {
        $listener = RunServerListener::getInstance();
        if ($listener->isRunning()) {
            $listener->stop();
        }
        $this->removeDir($this->docRoot);
    }

    public function testVerboseStartReportsProcessDetails(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 2);
        $listener->start();

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertMatchesRegularExpression(
            '/Started PHP built-in server pid=\d+ host=127\.0\.0\.1 port=\d+ workers=2 observed_workers=\d+ log=.+/',
            $messages
        );
        $this->assertTrue($listener->isRunning());
        $this->assertNotNull($listener->getServerLogFile());
        $this->assertFileExists((string)$listener->getServerLogFile());

        $port = $listener->getPort();
        $pid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        $workerPids = $this->waitForChildProcesses($pid, 1);

        $listener->stop();

        $this->assertFalse($listener->isRunning());
        $this->assertFalse($this->isPidAlive($pid), 'Main server PID should be gone after stop()');
        foreach ($workerPids as $workerPid) {
            $this->assertFalse(
                $this->isPidAlive($workerPid),
                sprintf('Worker PID %d should be gone after stop()', $workerPid)
            );
        }
        $this->assertTrue(
            $this->canBindPort('127.0.0.1', $port),
            sprintf('Port %d should be free after stop() with workers=2', $port)
        );
    }

    public function testStopWithWorkersAllowsRestartOnSamePort(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 2);
        $listener->start();
        $port = $listener->getPort();
        $this->assertTrue($listener->isRunning());
        $listener->stop();
        $this->assertFalse($listener->isRunning());
        $this->assertTrue($this->canBindPort('127.0.0.1', $port));

        $restarted = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 2);
        $restarted->start();
        $this->assertSame($port, $restarted->getPort());
        $this->assertTrue($restarted->isRunning());
        $restarted->stop();
        $this->assertFalse($restarted->isRunning());
        $this->assertTrue($this->canBindPort('127.0.0.1', $port));
    }

    public function testVerboseStopReportsExitStatusAndOutput(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());

        @file_get_contents($listener::getServerRoot());
        $listener->stop();

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString('Stopping PHP built-in server pid=', $messages);
        $this->assertMatchesRegularExpression('/Server process exit status: \d+( \(.+\))?/', $messages);
        $this->assertStringContainsString('Server stdout/stderr:', $messages);
        $this->assertFalse($listener->isRunning());
    }

    /**
     * @dataProvider unexpectedTerminationSignals
     */
    public function testVerboseModePreservesUnexpectedTerminationStatus(
        string $signal,
        int $expectedStatus,
        string $expectedLabel,
    ): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Signal-based termination is only asserted on Unix.');
        }

        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());

        $pid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        $this->sendSignal($pid, $signal);
        $this->waitUntilGone($listener);

        $listener->stop();

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString(
            sprintf('Server process exit status: %d (possibly %s)', $expectedStatus, $expectedLabel),
            $messages
        );
        $this->assertStringContainsString('Server stdout/stderr:', $messages);
        $this->assertFalse($listener->isRunning());
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function unexpectedTerminationSignals(): array
    {
        return [
            'SIGTERM' => ['TERM', 143, 'SIGTERM'],
            'SIGKILL' => ['KILL', 137, 'SIGKILL'],
            'SIGSEGV' => ['SEGV', 139, 'SIGSEGV'],
        ];
    }

    public function testVerboseTeardownWhenProcessAlreadyGone(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());

        $pid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        $this->sendSignal($pid, 'TERM');
        $this->waitUntilGone($listener);

        $listener->stop();

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString(
            sprintf('Teardown: server process already gone (pid was %s).', $pid),
            $messages
        );
        $this->assertStringContainsString('Server process exit status: 143 (possibly SIGTERM)', $messages);
        $this->assertStringContainsString('Server stdout/stderr:', $messages);
        $this->assertStringNotContainsString('No such process', $messages);
        $this->assertFalse($listener->isRunning());
    }

    public function testVerboseStartFailureIncludesServerOutput(): void
    {
        $invalidRoot = $this->docRoot . '/not-a-directory.php';
        file_put_contents($invalidRoot, "<?php echo 'nope';\n");

        $listener = new RunServerListener(0, $invalidRoot, '127.0.0.1', '', 0);

        try {
            $listener->start();
            $this->fail('Expected server start to fail for an invalid document root.');
        } catch (\PhpBuiltin\Exception\ServerException $exception) {
            $messages = $exception->getMessage() . "\n" . implode("\n", $listener->getDiagnosticMessages());
            $this->assertStringContainsString('Failed to start server', $messages);
            $this->assertTrue(
                str_contains($messages, 'Server stdout/stderr:')
                || str_contains($messages, 'Server stdout/stderr log unreadable:')
                || str_contains($messages, 'Server process exit status:'),
                'Startup failure should preserve PHP built-in server output or exit status. Got: ' . $messages
            );
            $this->assertFalse($listener->isRunning());
        }
    }

    public function testHealthCheckPassesWhileServerIsRunning(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 2);
        $listener->start();

        $listener->assertServerHealthy('unit test');

        $this->assertTrue($listener->isRunning());
        $listener->stop();
    }

    public function testHealthCheckDetectsUnexpectedMainProcessTerminationAndPreservesDiagnostics(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Signal-based termination is only asserted on Unix.');
        }

        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $pid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        $diagnosticFiles = $listener->getDiagnosticFiles();
        $processMonitorFile = $diagnosticFiles['processes'];
        $this->assertNotNull($processMonitorFile);
        $processTimelineBeforeCrash = $this->waitForProcessTimeline((string)$processMonitorFile);

        $this->sendSignal($pid, 'SEGV');
        $this->waitUntilGone($listener);

        try {
            $listener->assertServerHealthy('after scenario');
            $this->fail('Expected health check to detect the terminated PHP server.');
        } catch (\PhpBuiltin\Exception\ServerException $exception) {
            $this->assertStringContainsString('became unhealthy', $exception->getMessage());
        }

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString('SERVER FAILURE DETECTED', $messages);
        $this->assertStringContainsString('process=gone', $messages);
        $this->assertStringContainsString('Server process exit status: 139 (possibly SIGSEGV)', $messages);
        $this->assertStringContainsString('server process group', $messages);
        $this->assertStringContainsString('memory', $messages);
        $this->assertStringContainsString('core pattern', $messages);
        $this->assertStringContainsString('PHP version', $messages);
        $this->assertStringContainsString('PHP configuration', $messages);
        $this->assertStringContainsString('PHP modules', $messages);
        $this->assertStringContainsString('Process timeline:', $messages);
        $this->assertStringContainsString((string)$pid, $processTimelineBeforeCrash);

        $listener->stop();

        foreach ($diagnosticFiles as $path) {
            if ($path !== null) {
                $this->assertFileExists($path, 'Unexpected failures should preserve diagnostic files.');
                @unlink($path);
            }
        }
    }

    public function testWorkerLossIsDetectedAndPreservedInDiagnostics(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Worker process handling is only asserted on Unix.');
        }

        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 2);
        $listener->start();
        $mainPid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        $workerPids = $this->waitForChildProcesses($mainPid, 2);
        $diagnosticFiles = $listener->getDiagnosticFiles();
        $workerMonitorFile = $diagnosticFiles['workers'];
        $this->assertNotNull($workerMonitorFile);
        $baselineTimeline = $this->waitForWorkerTimeline((string)$workerMonitorFile, 1);

        $killedWorker = $workerPids[0];
        $this->sendSignal($killedWorker, 'KILL');

        $timelineWithFailure = $this->waitForWorkerTimelineContaining(
            (string)$workerMonitorFile,
            sprintf('%d:Z', $killedWorker)
        );

        try {
            $listener->assertServerHealthy('after worker death');
            $this->fail('Expected health check to detect degraded PHP worker capacity.');
        } catch (\PhpBuiltin\Exception\ServerException $exception) {
            $this->assertStringContainsString('workers=1/2', $exception->getMessage());
        }

        $this->assertTrue($listener->isRunning());
        $this->assertStringContainsString(sprintf('master=%d', $mainPid), $timelineWithFailure);
        $this->assertStringContainsString(sprintf('%d:Z', $killedWorker), $timelineWithFailure);
        $this->assertNotSame($baselineTimeline, $timelineWithFailure);

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString('SERVER FAILURE DETECTED', $messages);
        $this->assertStringContainsString('process=alive', $messages);
        $this->assertStringContainsString('port=reachable', $messages);
        $this->assertStringContainsString('workers=1/2', $messages);
        $this->assertStringContainsString('Worker timeline:', $messages);
        $this->assertStringContainsString(sprintf('%d:Z', $killedWorker), $messages);
        if (PHP_OS_FAMILY === 'Linux') {
            $this->assertStringContainsString(
                sprintf('Worker termination pid=%d wait_status=9 signal=9 (SIGKILL) core_dumped=no', $killedWorker),
                $messages
            );
        }

        $listener->stop();

        foreach ($diagnosticFiles as $path) {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public function testVerboseWrapperEnablesCoreDumpsBestEffort(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();

        $files = $listener->getDiagnosticFiles();
        $this->assertNotNull($files['wrapper']);
        $wrapper = file_get_contents((string)$files['wrapper']);
        $this->assertIsString($wrapper);
        $this->assertStringContainsString('ulimit -c unlimited', $wrapper);

        $listener->stop();
    }

    public function testNonVerboseStopDoesNotEmitDiagnostics(): void
    {
        $listener = new RunServerListener(null, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());
        $listener->stop();

        $this->assertSame([], $listener->getDiagnosticMessages());
        $this->assertFalse($listener->isRunning());
    }

    private function waitForProcessTimeline(string $path): string
    {
        for ($i = 0; $i < 60; $i++) {
            $content = is_file($path) ? trim((string)file_get_contents($path)) : '';
            if ($content !== '') {
                return $content;
            }
            usleep(50000);
        }

        $this->fail(sprintf('Expected process timeline in %s', $path));
    }

    private function waitForWorkerTimeline(string $path, int $minimumLines): string
    {
        for ($i = 0; $i < 40; $i++) {
            $content = is_file($path) ? trim((string)file_get_contents($path)) : '';
            if ($content !== '' && count(explode("\n", $content)) >= $minimumLines) {
                return $content;
            }
            usleep(50000);
        }

        $this->fail(sprintf('Expected worker timeline in %s', $path));
    }

    private function waitForWorkerTimelineContaining(string $path, string $needle): string
    {
        for ($i = 0; $i < 40; $i++) {
            $content = is_file($path) ? trim((string)file_get_contents($path)) : '';
            if ($content !== '' && str_contains($content, $needle)) {
                return $content;
            }
            usleep(50000);
        }

        $this->fail(sprintf('Expected worker timeline in %s to contain %s', $path, $needle));
    }

    private function waitForChildProcesses(int $parentPid, int $minimumCount): array
    {
        for ($i = 0; $i < 40; $i++) {
            $children = $this->childPids($parentPid);
            if (count($children) >= $minimumCount) {
                return $children;
            }
            usleep(50000);
        }

        $this->fail(sprintf(
            'Expected at least %d child worker process(es) for pid %d',
            $minimumCount,
            $parentPid
        ));
    }

    /**
     * @return list<int>
     */
    private function childPids(int $parentPid): array
    {
        $output = [];
        exec(sprintf('pgrep -P %d', $parentPid), $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }

        $pids = [];
        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        exec(sprintf('ps %d', $pid), $result);

        return count($result) > 1;
    }

    private function canBindPort(string $host, int $port): bool
    {
        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock === false) {
            return false;
        }
        socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        $bound = @socket_bind($sock, $host, $port);
        socket_close($sock);

        return $bound === true;
    }

    private function sendSignal(int $pid, string $signal): void
    {
        $command = sprintf('kill -s %s %d', $signal, $pid);
        if ($this->isForeignProcess($pid)) {
            $command = 'sudo ' . $command;
        }
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, sprintf('Failed to send SIG%s to pid %d', $signal, $pid));
    }

    private function isForeignProcess(int $pid): bool
    {
        exec(sprintf('ps -o uid= -p %d', $pid), $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return false;
        }

        return (int) trim($output[0]) !== posix_getuid();
    }

    /**
     * @param list<string> $messages
     */
    private function extractPidFromDiagnostics(array $messages): int
    {
        foreach ($messages as $message) {
            if (preg_match('/pid=(\d+)/', $message, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        $this->fail('PID not found in diagnostic messages');
    }

    private function waitUntilGone(RunServerListener $listener): void
    {
        for ($i = 0; $i < 40; $i++) {
            if (!$listener->isRunning()) {
                return;
            }
            usleep(50000);
        }
        $this->fail('Server process did not exit after kill');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
