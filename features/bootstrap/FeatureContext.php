<?php
/**
 * @copyright Copyright (c) 2022, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
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
            Assert::assertTrue($this->server->isRunning());
        } else {
            Assert::assertFalse($this->server->isRunning());
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
        Assert::assertTrue($this->server->isRunning());
    }

    /**
     * @When stop server
     */
    public function stopServer()
    {
        $this->server->stop();
        Assert::assertFalse($this->server->isRunning());
    }

    /**
     * @When kill all instances
     */
    public function killAllInstances()
    {
        $this->server->killZombies();
        Assert::assertFalse($this->server->isRunning());
    }
}
