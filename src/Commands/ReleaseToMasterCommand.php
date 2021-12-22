<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class ReleaseToMasterCommand.
 */
class ReleaseToMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const TARGET_UPSTREAM_ID = 'drupal-recommended';
    private const EMPTY_UPSTREAM_ID = 'empty';

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
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function releaseToMaster(string $site_id, array $options = ['branch' => self::TARGET_GIT_BRANCH]): void
    {
        $this->setSite($site_id);
        $sourceBranch = $options['branch'];
        $localPath = $this->getLocalSitePath();

        $this->git = new Git($localPath);
        if (!$this->git->isRemoteBranchExists($sourceBranch)) {
            throw new TerminusException(sprintf('The source branch "%s" does not exist', $sourceBranch));
        }

        $targetCommitHash = $this->git->getHeadCommitHash($sourceBranch);
        $masterCommitHash = $this->git->getHeadCommitHash(Git::DEFAULT_BRANCH);
        if ($targetCommitHash === $masterCommitHash) {
            $this->log()->warning(
                sprintf(
                    'Abort: already released to "%s" (the "%s" git branch matches "%s")',
                    Git::DEFAULT_BRANCH,
                    Git::DEFAULT_BRANCH,
                    $sourceBranch
                )
            );

            return;
        }

        $backupBranchName = $this->getBackupBranchName();
        if (!$this->git->isRemoteBranchExists($backupBranchName)) {
            $masterBranchHeadCommitHash = $this->git->getHeadCommitHash(Git::DEFAULT_BRANCH);
            $this->log()->notice(
                sprintf(
                    'Creating backup of "%s" ("%s" commit)...',
                    Git::DEFAULT_BRANCH,
                    $masterBranchHeadCommitHash
                )
            );
            $this->git->checkout(
                '--no-track',
                '-b',
                $backupBranchName,
                sprintf('%s/%s', Git::DEFAULT_REMOTE, Git::DEFAULT_BRANCH)
            );
            $this->git->push($backupBranchName);
            $this->createMultidev($backupBranchName);
        } else {
            $this->log()->notice(
                sprintf(
                    'Skipped creating a backup branch and a multidev env: "%s" already exists',
                    $backupBranchName
                )
            );
        }

        if (!$this->input()->getOption('yes') && !$this->io()
                ->confirm(
                    sprintf(
                        'Are you sure you want to replace "%s" with "%s" git branch?',
                        Git::DEFAULT_BRANCH,
                        $sourceBranch
                    ),
                    false
                )
        ) {
            return;
        }

        $this->log()->notice(sprintf('Replacing "%s" with "%s" git branch...', Git::DEFAULT_BRANCH, $sourceBranch));
        $this->git->checkout(Git::DEFAULT_BRANCH);
        $this->git->reset('--hard', $targetCommitHash);
        $this->git->push(Git::DEFAULT_BRANCH, '--force');

        if (self::EMPTY_UPSTREAM_ID !== $this->site()->getUpstream()->get('machine_name')
            || $this->input()->getOption('yes')
            || $this->io()->confirm(
                sprintf(
                    'Switch to "%s" upstream (currently on "%s")?',
                    self::TARGET_UPSTREAM_ID,
                    self::EMPTY_UPSTREAM_ID
                ),
                false
            )
        ) {
            $this->switchUpstream(self::TARGET_UPSTREAM_ID);
        }

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site()->getEnvironments()->get('dev');
        $this->log()->notice(sprintf('Link to "dev" environment dashboard: %s', $devEnv->dashboardUrl()));

        $this->log()->notice('Done!');
    }
}
