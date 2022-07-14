<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Helpers\LocalMachineHelper;

/**
 * Trait DrushCommandsTrait.
 */
trait DrushCommandsTrait
{

    /**
     * Run drush updb -y after waiting for the site to be synced.
     *
     * @param array $options
     *   The options passed to the original command.
     * @param string|null $env
     *   The environment to wait for code sync.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function executeDrushDatabaseUpdates(array $options, ?string $env = null): void
    {
        if (!($options['run-updb'] ?? false)) {
            return;
        }

        $env = $env ?? $options['branch'] ?? 'dev';
        $this->waitForSyncCodeWorkflow($env);

        $this->runDrushCommand('updb -y', $env);
    }

    /**
     * Run drush cr after waiting for the site to be synced.
     *
     * @param array $options
     *   The options passed to the original command.
     * @param string|null $env
     *   The environment to wait for code sync.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function executeDrushCacheRebuild(array $options, ?string $env = null): void
    {
        if (!($options['run-cr'] ?? false)) {
            return;
        }

        $env = $env ?? $options['branch'] ?? 'dev';
        $this->waitForSyncCodeWorkflow($env);

        $this->runDrushCommand('cr -y', $env);
    }

    /**
     * Run given drush command in current site.
     *
     * @param string $command
     *   Command to run.
     * @param string $env
     *  Environment to run command in.
     *
     * @return array
     *   The output and exit code of the command.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function runDrushCommand($command, $env): array
    {
        $fullCommand = sprintf('drush %s', $command);
        $sshCommand = $this->getConnectionString($env) . ' ' . escapeshellarg($fullCommand);
        $this->logger->debug('shell command: {command}', [ 'command' => $fullCommand ]);
        return $this->getContainer()->get(LocalMachineHelper::class)->exec($sshCommand);
    }

    /**
     * Returns the connection string.
     *
     * @param string $env
     *   Environment to get the connection string for.
     *
     * @return string
     *   SSH connection string.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function getConnectionString(string $env): string
    {
        $environment = $this->getEnv(sprintf('%s.%s', $this->site()->getName(), $env));
        $sftp = $environment->sftpConnectionInfo();
        $command = $this->getConfig()->get('ssh_command');

        return vsprintf(
            '%s -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            [$command, $sftp['username'], $sftp['host'], $sftp['port'],]
        );
    }
}
