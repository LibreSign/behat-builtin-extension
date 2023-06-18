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

namespace PhpBuiltin;

use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTeardown;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use PhpBuiltin\Exception\ServerException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RunServerListener implements EventSubscriberInterface
{
    private string $pid = '0';
    private static string $host;
    private static int $port = 0;
    private ?int $verbose = null;
    private string $rootDir;
    private string $runAs = '';
    private static self $instance;

    public function __construct(?int $verbose, string $rootDir, string $host, string $runAs)
    {
        $this->verbose = $verbose;
        $this->rootDir = $rootDir;
        $this->runAs = $runAs;
        self::$host = $host;
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

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

        $cmd = 'php -S ' . self::$host .':' . self::$port . ' -t ' . $script;

        if ($this->runAs && get_current_user() !== $this->runAs) {
            $cmd = 'runuser -u ' . $this->runAs . ' -- ' . $cmd;
        }

        if (is_numeric($this->verbose)) {
            $verbose = '';
        } else {
            $verbose = '2>&1';
        }

        $fullCmd = $this->parseCommand(sprintf(
            '%s > /dev/null %s & echo $!',
            escapeshellcmd($cmd),
            $verbose
        ));

        $this->pid = (string)(int) exec($fullCmd);

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
            throw new ServerException(
                'Failed to start server. Is something already running on port ' . self::$port . "?\n" .
                'Full command: ' . $fullCmd
            );
        }

        register_shutdown_function(function () {
            if ($this->isRunning()) {
                $this->stop();
            }
        });
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
        if ($this->pid) {
            exec($this->parseCommand('kill ' . $this->pid));
        }

        $this->killZombies();

        $this->pid = '0';
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
                exec($this->parseCommand('kill ' . $pid));
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
     * Let the OS find an open port for you.
     *
     * @return int
     *
     * @psalm-return int<1, max>
     */
    private function findOpenPort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, 0);

        // Bind the socket to an address/port
        if (!socket_bind($sock, self::$host, 0)) {
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
}
