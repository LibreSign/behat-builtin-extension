<?php
/**
 * @copyright Copyright (c) 2026, LibreCode coop and contributors
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

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
        $this->assertMatchesRegularExpression('/Server process exit status: \d+/', $messages);
        $this->assertStringContainsString('Server stdout/stderr:', $messages);
        $this->assertFalse($listener->isRunning());
    }

    public function testVerboseTeardownWhenProcessAlreadyGone(): void
    {
        $listener = new RunServerListener(0, $this->docRoot, '127.0.0.1', '', 0);
        $listener->start();
        $this->assertTrue($listener->isRunning());

        $pid = $this->extractPidFromDiagnostics($listener->getDiagnosticMessages());
        exec('kill ' . $pid);
        $this->waitUntilGone($listener);

        $listener->stop();

        $messages = implode("\n", $listener->getDiagnosticMessages());
        $this->assertStringContainsString(
            sprintf('Teardown: server process already gone (pid was %s).', $pid),
            $messages
        );
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

    /**
     * @param list<string> $messages
     */
    private function extractPidFromDiagnostics(array $messages): string
    {
        foreach ($messages as $message) {
            if (preg_match('/pid=(\d+)/', $message, $matches) === 1) {
                return $matches[1];
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
