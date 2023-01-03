<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class ReleaseToMasterCommand.
 */
class ReleaseToMasterCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const TARGET_UPSTREAM_ID = 'drupal-composer-managed';
    private const EMPTY_UPSTREAM_ID = 'empty';

    /**
     * Releases a converted Drupal site managed by Composer to the dev environment :
     * 1) creates a backup for the dev environment branch;
     * 2) replaces the dev environment git branch and its commit history with the source Multidev's commit history
     * (a converted Drupal site).
     *
     * @command conversion:release-to-dev
     *
     * @option branch The source git branch name (Multidev environment name).
     * @option run-updb Run `drush updb` after conversion.
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on.
     * @param array $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function releaseToMaster(string $site_id, array $options = [
        'branch' => self::TARGET_GIT_BRANCH,
        'run-updb' => true,
        'run-cr' => true,
    ]): void
    {
        $this->setSite($site_id);
        $sourceBranch = $options['branch'];
        $localPath = $this->getLocalSitePath();

        $this->setGit($localPath);

        $this->getGit()->fetch('origin');
        $this->getGit()->merge('origin/master');

        if (!$this->getGit()->isRemoteBranchExists($sourceBranch)) {
            throw new TerminusException(sprintf('The source branch "%s" does not exist', $sourceBranch));
        }

        $targetCommitHash = $this->getGit()->getHeadCommitHash($sourceBranch);
        $masterCommitHash = $this->getGit()->getHeadCommitHash(Git::DEFAULT_BRANCH);
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
        if (!$this->getGit()->isRemoteBranchExists($backupBranchName)) {
            $masterBranchHeadCommitHash = $this->getGit()->getHeadCommitHash(Git::DEFAULT_BRANCH);
            $this->log()->notice(
                sprintf(
                    'Creating backup of "%s" ("%s" commit)...',
                    Git::DEFAULT_BRANCH,
                    $masterBranchHeadCommitHash
                )
            );
            $this->getGit()->checkout(
                '--no-track',
                '-b',
                $backupBranchName,
                sprintf('%s/%s', Git::DEFAULT_REMOTE, Git::DEFAULT_BRANCH)
            );
            $this->getGit()->push($backupBranchName);
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
        $this->getGit()->checkout(Git::DEFAULT_BRANCH);
        $this->getGit()->reset('--hard', $targetCommitHash);
        $this->getGit()->push(Git::DEFAULT_BRANCH, '--force');

        $this->executeDrushDatabaseUpdates($options, 'dev');
        $this->executeDrushCacheRebuild($options, 'dev');


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

        $this->output()->writeln(
            <<<EOD
Now that the code has been pushed to the dev environment, you can test everything there and when
everything is ready, you can deploy to the test and live environments when it makes sense to you.
EOD
        );

        $this->log()->notice('Done!');
    }
}
