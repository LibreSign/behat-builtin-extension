<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace PhpBuiltin\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UnexpectedCrashBehatTest extends TestCase
{
    public function testUnexpectedCrashIsDetectedByRealBehatRun(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Signal-based crash test is only supported on Unix.');
        }

        $root = dirname(__DIR__, 2);
        $command = sprintf(
            'cd %s && BEHAT_VERBOSE=1 %s -p diagnostic --tags=%s 2>&1',
            escapeshellarg($root),
            escapeshellarg($root . '/vendor/bin/behat'),
            escapeshellarg('@diagnostic-crash')
        );

        $output = [];
        exec($command, $output, $exitCode);
        $text = implode("\n", $output);

        $this->assertNotSame(0, $exitCode, 'The diagnostic Behat run must fail after the forced crash.');
        $this->assertStringContainsString('SERVER FAILURE DETECTED', $text);
        $this->assertStringContainsString('Server process exit status: 139 (possibly SIGSEGV)', $text);
        $this->assertStringContainsString('PHP built-in server became unhealthy', $text);
        $this->assertStringNotContainsString('SCENARIO_AFTER_CRASH_EXECUTED', $text);
        $this->assertSame(1, substr_count($text, 'SERVER FAILURE DETECTED'));
    }
}
