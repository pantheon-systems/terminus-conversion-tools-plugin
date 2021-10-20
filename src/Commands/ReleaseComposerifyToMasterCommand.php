<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class ReleaseComposerifyToMasterCommand.
 */
class ReleaseComposerifyToMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;
    use ConversionCommandsTrait;

    private const COMPOSERIFY_GIT_BRANCH = 'composerify';
    private const BACKUP_GIT_BRANCH = 'master-bckp';
    private const MASTER_GIT_BRANCH = 'master';
    private const DRUPAL9_UPSTREAM = 'drupal9';

    /**
     * Releases a converted Drupal8 site managed by Composer to the master git branch:
     * 1) creates a backup for the master git branch;
     * 2) replaces the master git branch and its commit history with the source Multidev's commit history (a converted
     * Drupal8 site).
     *
     * @command conversion:release-to-master
     *
     * @option branch The source git branch name (Multidev environment name).
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function convert(string $site_id, array $options = ['branch' => self::COMPOSERIFY_GIT_BRANCH]): void
    {
        $site = $this->getSite($site_id);

        $sourceBranch = $options['branch'];

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $site->getEnvironments()->get('dev');

        $localPath = $this->cloneSiteGitRepository(
            $site,
            sprintf('%s_composer_conversion', $site->getName())
        );

        $git = new Git($localPath);
        if (!$git->isRemoteBranchExists($sourceBranch)) {
            throw new TerminusException(sprintf('The source branch "%s" does not exist', $sourceBranch));
        }

        if (!$git->isRemoteBranchExists(self::BACKUP_GIT_BRANCH)) {
            $masterBranchHeadCommitHash = $git->getHeadCommitHash(self::MASTER_GIT_BRANCH);
            $this->log()->notice(
                sprintf(
                    'Creating backup of "%s" ("%s" commit)...',
                    self::MASTER_GIT_BRANCH,
                    $masterBranchHeadCommitHash
                )
            );
            $git->checkout('--no-track', '-b', self::BACKUP_GIT_BRANCH, sprintf('origin/%s', self::MASTER_GIT_BRANCH));
            $git->push(self::BACKUP_GIT_BRANCH);
            $this->createMultidev($site, self::BACKUP_GIT_BRANCH);
        } else {
            $this->log()->notice(
                sprintf(
                    'Skipped creating a backup multidev env: "%s" already exists',
                    self::BACKUP_GIT_BRANCH
                )
            );
        }

        $this->log()->notice(sprintf('Replacing "%s" with "%s" git branch...', self::MASTER_GIT_BRANCH, $sourceBranch));
        $git->checkout(self::MASTER_GIT_BRANCH);
        $git->reset('--hard', $git->getHeadCommitHash($sourceBranch));
        $git->push(self::MASTER_GIT_BRANCH, '--force');

        $this->log()->notice(sprintf('Changing the upstream to "%s"...', self::DRUPAL9_UPSTREAM));
        $upstream = $this->session()->getUser()->getUpstreams()->get(self::DRUPAL9_UPSTREAM);
        $this->processWorkflow($site->setUpstream($upstream->id));

        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));
    }
}
