<?php

/**
 * SPDX-FileCopyrightText: 2022 Vitor Mattos <vitor@php.rio>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Behat\Behat\Context\Context;
use PhpBuiltin\RunServerListener;
use PHPUnit\Framework\Assert;

class FeatureContext implements Context
{
    /** @var RunServerListener */
    private $server;
    public function __construct()
    {
        $this->server = RunServerListener::getInstance();
    }

    /**
     * @When /^server is (up|down)$/
     */
    public function serverIsUp(string $status)
    {
        if ($status === 'up') {
            Assert::assertTrue($this->server->isRunning(), 'Server is up?');
        } else {
            Assert::assertFalse($this->server->isRunning(), 'Server is down?');
        }
    }

    /**
     * @When the host of server is :host
     */
    public function theHostOfServerIs($host)
    {
        Assert::assertEquals($host, $this->server->getHost());
    }

    /**
     * @When start server
     */
    public function startServer()
    {
        $this->server->start();
        Assert::assertTrue($this->server->isRunning(), 'Server is running after start?');
    }

    /**
     * @When stop server
     */
    public function stopServer()
    {
        $this->server->stop();
        Assert::assertFalse($this->server->isRunning(), 'Server is stopped after stop?');
    }

    /**
     * @When kill server unexpectedly with :signal
     */
    public function killServerUnexpectedlyWith(string $signal): void
    {
        if (preg_match('/^[A-Z0-9]+$/', $signal) !== 1) {
            throw new \RuntimeException('Invalid signal name');
        }

        $pid = null;
        foreach ($this->server->getDiagnosticMessages() as $message) {
            if (preg_match('/Started PHP built-in server pid=(\d+)/', $message, $matches) === 1) {
                $pid = (int)$matches[1];
            }
        }
        if ($pid === null) {
            throw new \RuntimeException('PHP built-in server PID not found in diagnostics');
        }

        exec(sprintf('kill -s %s %d', $signal, $pid), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Failed to send SIG%s to pid %d', $signal, $pid));
        }

        for ($i = 0; $i < 40; $i++) {
            if (!$this->server->isRunning()) {
                return;
            }
            usleep(50000);
        }

        throw new \RuntimeException(sprintf('Server pid %d did not terminate', $pid));
    }

    /**
     * @Then diagnostic marker after crash is executed
     */
    public function diagnosticMarkerAfterCrashIsExecuted(): void
    {
        throw new \RuntimeException('SCENARIO_AFTER_CRASH_EXECUTED');
    }

    /**
     * @When kill all instances
     */
    public function killAllInstances()
    {
        $this->server->stop();
        Assert::assertFalse($this->server->isRunning(), 'Is server running after run kill?');
    }
}
