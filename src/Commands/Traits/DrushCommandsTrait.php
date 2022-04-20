<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Helpers\LocalMachineHelper;

/**
 * Trait DrushCommandsTrait.
 */
trait DrushCommandsTrait
{

    /**
     * Run given drush command in current site.
     */
    private function runDrushCommand($command)
    {
        $fullCommand = sprintf('drush %s', $command);
        $sshCommand = $this->getConnectionString() . ' ' . escapeshellarg($fullCommand);
        $this->logger->debug('shell command: {command}', [ 'command' => $fullCommand ]);
        $result = $this->getContainer()->get(LocalMachineHelper::class)->exec($sshCommand);
    }

    /**
     * Returns the connection string.
     *
     * @return string
     *   SSH connection string.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function getConnectionString()
    {
        $environment = $this->getEnv(sprintf('%s.dev', $this->site()->getName()));
        $sftp = $environment->sftpConnectionInfo();
        $command = $this->getConfig()->get('ssh_command');

        return vsprintf(
            '%s -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            [$command, $sftp['username'], $sftp['host'], $sftp['port'],]
        );
    }
}
