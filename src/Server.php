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

use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Exception;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class Server implements Extension
{
    public const ID = 'php_builtin_server';
    /**
     * Returns the extension config key.
     *
     * @return string
     */
    public function getConfigKey(): string
    {
        return self::ID;
    }

    public function initialize(ExtensionManager $extensionManager): void
    {
    }

    /**
     * Setups configuration for the extension.
     *
     * @param ArrayNodeDefinition $builder
     * @psalm-suppress PossiblyUndefinedMethod
     * @psalm-suppress PossiblyNullReference
     */
    public function configure(ArrayNodeDefinition $builder): void
    {
        $builder
            ->children()
                ->booleanNode('verbose')
                    ->info('Enables/disables verbose mode')
                    ->defaultFalse()
                ->end()
                ->scalarNode('rootDir')
                    ->info('Specifies http root dir')
                    ->defaultValue('/var/www/html')
                ->end()
                ->scalarNode('host')
                    ->info('Host domain or IP')
                    ->defaultValue('localhost')
                ->end()
                ->scalarNode('runAs')
                    ->info('The username to be used to run the built-in server')
                    ->defaultValue('')
                ->end()
                ->scalarNode('workers')
                    ->info(
                        'The quantity of workers to use. ' .
                        'More informations here ' .
                        'https://www.php.net/manual/en/features.commandline.webserver.php ' .
                        'searching by PHP_CLI_SERVER_WORKERS'
                    )
                    ->defaultValue(0)
                ->end()
            ->end()
        ;
    }

    /** @inheritDoc */
    public function load(ContainerBuilder $container, array $config): void
    {
        $rootDir = $this->getRootDir($config);
        $host = $this->getHost($config);
        $runAs = $this->getRunAs($config);
        $workers = $this->getWorkers($config);
        $verbose = $this->getVerboseLevel($container, $config);
        if (is_numeric($verbose)) {
            $output = $container->get('cli.output');
            if ($output instanceof OutputInterface) {
                $output->writeln('<info>Root dir: ' . $rootDir . '</info>');
                $output->writeln('<info>Verbose: ' . $verbose . '</info>');
                $output->writeln('<info>Host: ' . $host . '</info>');
                $output->writeln('<info>RunAs: ' . $runAs . '</info>');
                $output->writeln('<info>Workers: ' . $workers . '</info>');
            }
        }
        $definition = (new Definition('PhpBuiltin\RunServerListener'))
            ->addTag('event_dispatcher.subscriber')
            ->setArguments([$verbose, $rootDir, $host, $runAs, $workers])
        ;

        $container->setDefinition(self::ID . '.listener', $definition);
    }

    private function getHost(array $config): string
    {
        $host = getenv('BEHAT_HOST');
        if ($host === false) {
            $host = $config['host'];
        }
        return (string) $host;
    }

    private function getRunAs(array $config): string
    {
        $runAs = getenv('BEHAT_RUN_AS');
        if ($runAs === false) {
            $runAs = $config['runAs'];
        }
        return (string) $runAs;
    }

    private function getWorkers(array $config): string
    {
        $workers = getenv('BEHAT_WORKERS');
        if ($workers === false) {
            $workers = $config['workers'];
        }
        return (string) $workers;
    }

    private function getRootDir(array $config): string
    {
        $rootDir = getenv('BEHAT_ROOT_DIR');
        if ($rootDir === false) {
            $rootDir = $config['rootDir'];
        }
        $rootDir = (string) realpath($rootDir);
        if (empty($rootDir) || !is_dir($rootDir)) {
            throw new Exception('Invalid root dir [' . $rootDir . '], define BEHAT_ROOT_DIR environment or rootDir config value with valid path');
        }
        return $rootDir;
    }

    /**
     * @psalm-suppress PossiblyNullReference
     */
    private function getVerboseLevel(ContainerBuilder $container, array $config): ?int
    {
        /** @var ArgvInput */
        $input = $container->get('cli.input');
        if (!$input instanceof ArgvInput) {
            return null;
        }
        if ($input->hasParameterOption('--verbose')) {
            $verbose = $input->getParameterOption('--verbose');
            return (int)($verbose ?? 0);
        }
        if ($input->hasParameterOption('-v')) {
            $verbose = $input->getParameterOption('-v');
            return strlen($verbose);
        }
        $runAs = getenv('BEHAT_VERBOSE');
        if (is_numeric($runAs)) {
            return (int) $runAs;
        }
        return $config['verbose'] ? 0 : null;
    }

    public function process(ContainerBuilder $container): void
    {
    }
}
