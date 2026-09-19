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
            '/Started PHP built-in server pid=\d+ host=127\.0\.0\.1 port=\d+ workers=2 log=.+/',
            $messages
        );
        $this->assertTrue($listener->isRunning());
        $this->assertNotNull($listener->getServerLogFile());
        $this->assertFileExists((string)$listener->getServerLogFile());

        $listener->stop();
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

    public function testNonVerboseStopDoesNotEmitDiagnostics(): void
    {
        $listener = new RunServerListener(null, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());
        $listener->stop();

        $this->assertSame([], $listener->getDiagnosticMessages());
        $this->assertFalse($listener->isRunning());
    }

    private function sendSignal(int $pid, string $signal): void
    {
        $command = sprintf('kill -s %s %d', $signal, $pid);
        if (getenv('GITHUB_ACTIONS') !== false) {
            $command = 'sudo ' . $command;
        }
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, sprintf('Failed to send SIG%s to pid %d', $signal, $pid));
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
