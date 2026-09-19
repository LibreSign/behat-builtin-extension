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
     * @When kill all instances
     */
    public function killAllInstances()
    {
        $this->server->stop();
        Assert::assertFalse($this->server->isRunning(), 'Is server running after run kill?');
    }
}
