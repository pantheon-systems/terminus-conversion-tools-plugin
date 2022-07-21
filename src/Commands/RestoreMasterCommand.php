<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class RestoreMasterCommand.
 */
class RestoreMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use DrushCommandsTrait;

    /**
     * Restore the dev environment branch to the state before converting a standard Drupal site into a Drupal site
     * managed by Composer.
     *
     * @command conversion:restore-dev
     *
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on.
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function restoreMaster(string $site_id, array $options = ['run-cr' => true]): void
    {
        $this->setSite($site_id);

        $localPath = $this->getLocalSitePath();

        $this->setGit($localPath);
        $backupBranchName = $this->getBackupBranchName();
        if (!$this->getGit()->isRemoteBranchExists($backupBranchName)) {
            throw new TerminusException(sprintf('The backup git branch "%s" does not exist', $backupBranchName));
        }

        $backupMasterCommitHash = $this->getGit()->getHeadCommitHash($backupBranchName);
        $masterCommitHash = $this->getGit()->getHeadCommitHash(Git::DEFAULT_BRANCH);
        if ($backupMasterCommitHash === $masterCommitHash) {
            $this->log()->warning(
                sprintf(
                    'Abort: the backup git branch "%s" matches "%s"',
                    $backupBranchName,
                    Git::DEFAULT_BRANCH
                )
            );

            return;
        }

        if (!$this->input()->getOption('yes')
            && !$this->io()->confirm(
                sprintf(
                    'Are you sure you want to restore "%s" git branch to "%s" (the head commit of "%s" git branch)?',
                    Git::DEFAULT_BRANCH,
                    $backupMasterCommitHash,
                    $backupBranchName
                )
            )
        ) {
            return;
        }

        $this->log()->notice(
            sprintf('Restoring "%s" git branch to "%s"...', Git::DEFAULT_BRANCH, $backupMasterCommitHash)
        );
        $this->getGit()->checkout(Git::DEFAULT_BRANCH);
        $this->getGit()->reset('--hard', $backupMasterCommitHash);
        $this->getGit()->push(Git::DEFAULT_BRANCH, '--force');

        $this->executeDrushCacheRebuild($options, 'dev');

        $this->switchUpstream($this->getSourceUpstreamIdByBackupBranchName($backupBranchName));

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site()->getEnvironments()->get('dev');
        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));

        $this->log()->notice(sprintf('Dev environment has been restored from the multidev env %s', $backupBranchName));

        $this->log()->notice('Done!');
    }
}
