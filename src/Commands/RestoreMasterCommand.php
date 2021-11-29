<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class RestoreMasterCommand.
 */
class RestoreMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    /**
     * Restore the master branch to the state before converting a standard Drupal8 site into a Drupal8 site managed by
     * Composer.
     *
     * @command conversion:restore-master
     *
     * @param string $site_id
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function restoreMaster(string $site_id): void
    {
        $this->site = $this->getSite($site_id);

        $localPath = $this->cloneSiteGitRepository();

        $this->git = new Git($localPath);
        $backupBranchName = $this->getBackupBranchName();
        if (!$this->git->isRemoteBranchExists($backupBranchName)) {
            throw new TerminusException(sprintf('The backup git branch "%s" does not exist', $backupBranchName));
        }

        $backupMasterCommitHash = $this->git->getHeadCommitHash($backupBranchName);
        $masterCommitHash = $this->git->getHeadCommitHash(Git::DEFAULT_BRANCH);
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
        $this->git->checkout(Git::DEFAULT_BRANCH);
        $this->git->reset('--hard', $backupMasterCommitHash);
        $this->git->push(Git::DEFAULT_BRANCH, '--force');

        $this->switchUpstream($this->getSourceUpstreamIdByBackupBranchName($backupBranchName));

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site->getEnvironments()->get('dev');
        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));
    }
}
