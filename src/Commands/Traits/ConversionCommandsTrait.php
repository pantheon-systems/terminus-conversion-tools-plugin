<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\TerminusModel;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitException;

/**
 * Trait ConversionCommandsTrait.
 */
trait ConversionCommandsTrait
{
    use GitAwareTrait;
    use MultidevBranchAwareTrait;
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    /**
     * @var \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private $localMachineHelper;

    /**
     * @var \Pantheon\Terminus\Models\Site
     */
    private $site;

    /**
     * @var string
     */
    private string $localSitePath;

    /**
     * @var string
     */
    private string $terminusExecutable;
  
    /**
     * @var string
     */
    private string $siteGitRemoteUrl;

    /**
     * Clones the site repository to local machine and return the absolute path to the local copy.
     *
     * @param bool $force
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    protected function cloneSiteGitRepository(bool $force = true, string $remoteGitUrl = null): string
    {
        $siteDirName = sprintf('%s_terminus_conversion_plugin', $this->site->getName());
        $path = $this->site->getLocalCopyDir($siteDirName);
        if (!$force && 2 < count(scandir($path))) {
            return $path;
        }

        $this->log()->notice(
            sprintf('Cloning %s site repository into "%s"...', $this->site->getName(), $path)
        );

        $remoteGitUrl = $remoteGitUrl ?: $this->getRemoteGitUrl();

        $this->getLocalMachineHelper()->cloneGitRepository($remoteGitUrl, $path, true);

        return $path;
    }

    /**
     * Returns the site Git remote URL.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function getRemoteGitUrl(): string
    {
        if (isset($this->siteGitRemoteUrl)) {
            return $this->siteGitRemoteUrl;
        }

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site->getEnvironments()->get('dev');
        $connectionInfo = $devEnv->connectionInfo();

        if (!isset($connectionInfo['git_url'])) {
            throw new TerminusException('Failed to get site Git URL');
        }

        return $this->siteGitRemoteUrl = $connectionInfo['git_url'];
    }

    /**
     * Clones the site and returns the path to the local site copy.
     *
     * @param null|bool $force
     * @param string $remoteGitUrl
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function getLocalSitePath(?bool $force = null, string $remoteGitUrl = null)
    {
        if (true !== $force && isset($this->localSitePath)) {
            return $this->localSitePath;
        }

        $existingLocalGitRepo = $this->detectLocalGitRepo();
        if (null !== $existingLocalGitRepo) {
            if ($this->input()->getOption('yes') || $this->io()
                    ->confirm(
                        sprintf(
                            <<<EOD
An existing local site repository found in "%s".
Do you want to proceed with it (a temporary copy will be cloned otherwise)?
EOD,
                            $existingLocalGitRepo
                        )
                    )
            ) {
                $this->log()->notice(sprintf('Local git repository path is set to "%s".', $existingLocalGitRepo));

                return $this->localSitePath = $existingLocalGitRepo;
            }
        }

        $this->localSitePath = null === $force
            ? $this->cloneSiteGitRepository(false, $remoteGitUrl)
            : $this->cloneSiteGitRepository($force, $remoteGitUrl);

        $this->getLocalMachineHelper()->exec(sprintf('git -C %s checkout %s', $this->localSitePath, Git::DEFAULT_BRANCH));
        $this->log()->notice(sprintf('Local git repository path is set to "%s".', $this->localSitePath));

        return $this->localSitePath;
    }

    /**
     * Returns an absolute local git repository path or NULL if not detected.
     *
     * @return string|null
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function detectLocalGitRepo(): ?string
    {
        $possibleLocalRepoPath = getcwd();
        try {
            $git = new Git($possibleLocalRepoPath);
            $localGitRemote = $git->getConfig('remote.origin.url');
            return $this->getRemoteGitUrl() === $localGitRemote ? $git->getToplevelRepoPath() : null;
        } catch (TerminusException $exception) {
            return null;
        }
    }

    /**
     * Returns root composer.json file contents.
     *
     * @param string|null $filePath
     *   Use specific composer.json file, otherwise defaults to local site's one.
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getComposerJson(?string $filePath = null): array
    {
        $filePath = $filePath ?? Files::buildPath($this->getLocalSitePath(), 'composer.json');
        if (!file_exists($filePath)) {
            return [];
        }

        return json_decode(file_get_contents($filePath), true);
    }

    /**
     * Creates the multidev environment.
     *
     * @param string $branch
     *
     * @return \Pantheon\Terminus\Models\Environment
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function createMultidev(string $branch): TerminusModel
    {
        $this->log()->notice(sprintf('Creating "%s" multidev environment...', $branch));

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site->getEnvironments()->get('dev');

        $workflow = $this->site->getEnvironments()->create($branch, $devEnv);
        $this->processWorkflow($workflow);
        $this->site->unsetEnvironments();

        return $this->site->getEnvironments()->get($branch);
    }

    /**
     * Switches the site upstream.
     *
     * @param string $upstreamId
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function switchUpstream(string $upstreamId): void
    {
        $this->log()->notice(sprintf('Changing the upstream to "%s"...', $upstreamId));
        $upstream = $this->session()->getUser()->getUpstreams()->get($upstreamId);
        $this->processWorkflow($this->site->setUpstream($upstream->id));
    }

    /**
     * Returns the backup git branch (multidev env) name.
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function getBackupBranchName(): string
    {
        $backupBranchNamePrefix = 'mstr-bkp-';

        // Check if the backup branch already exists.
        foreach (array_keys($this->getSupportedSourceUpstreamIds()) as $upstreamAlias) {
            $backupBranchName = $backupBranchNamePrefix . $upstreamAlias;
            if ($this->getGit()->isRemoteBranchExists($backupBranchName)) {
                return $backupBranchName;
            }
        }

        // Construct the name to include the upstream alias as a two letters short suffix.
        $sourceUpstreamId = $this->site->getUpstream()->get('machine_name');
        $upstreamAlias = array_search($sourceUpstreamId, $this->getSupportedSourceUpstreamIds(), true);
        if (false === $upstreamAlias) {
            throw new TerminusException('Unsupported upstream {upstream}', ['upstream' => $sourceUpstreamId]);
        }

        return $backupBranchNamePrefix . $upstreamAlias;
    }

    /**
     * Returns the list of supported upstreams.
     *
     * @return string[]
     *   Key is the two letters short alias of the upstream;
     *   Value is the ID of the upstream.
     */
    protected function getSupportedSourceUpstreamIds(): array
    {
        return [
            'd8' => 'drupal8',
            'em' => 'empty',
            'd9' => 'drupal9',
        ];
    }

    /**
     * Returns the ID of the source upstream extracted from the backup branch name.
     *
     * @param string $backupBranchName
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function getSourceUpstreamIdByBackupBranchName(string $backupBranchName): string
    {
        $upstreamAlias = substr($backupBranchName, -2);
        if (!isset($this->getSupportedSourceUpstreamIds()[$upstreamAlias])) {
            throw new TerminusException(
                'Failed to get the source upstream by upstream alias {upstream_alias}.',
                ['upstream_alias' => $upstreamAlias]
            );
        }

        return $this->getSupportedSourceUpstreamIds()[$upstreamAlias];
    }

    /**
     * Creates the target local git branch based on Pantheon's "drupal-recommended" upstream and returns the name of
     * the git remote.
     *
     * @param string $remoteUrl
     *   Url from remote repository.
     * @param string $remoteBranch
     *   Branch name from remote repository.
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function createLocalGitBranchFromRemote(string $remoteUrl, string $remoteBranch = Git::DEFAULT_BRANCH): string
    {
        $targetGitRemoteName = 'target-upstream';
        $this->log()->notice(
            sprintf('Creating "%s" git branch based on "drupal-recommended" upstream...', $this->getBranch())
        );
        $this->getGit()->addRemote($remoteUrl, $targetGitRemoteName);
        $this->getGit()->fetch($targetGitRemoteName);
        $this->getGit()->checkout(
            '--no-track',
            '-b',
            $this->getBranch(),
            $targetGitRemoteName . '/' . $remoteBranch
        );

        return $targetGitRemoteName;
    }

    /**
     * Pushes the target branch to the site repository.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function pushTargetBranch(): void
    {
        try {
            $this->deleteMultidevIfExists($this->getBranch());
        } catch (TerminusCancelOperationException $e) {
            return;
        }

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', $this->getBranch()));
        $this->getGit()->push($this->getBranch());

        $mdEnv = $this->createMultidev($this->getBranch());
        $this->log()->notice(
            sprintf('Link to "%s" multidev environment dashboard: %s', $this->getBranch(), $mdEnv->dashboardUrl())
        );
    }

    /**
     * Pushes the target branch to the site repository for a build tools site.
     *
     * @param string $remote
     *   Remote name to push to.
     * @param string $upstreamBranch
     *   Upstream branch name.
     * @param bool dryRun
     *   Whether to do a dry run.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    protected function pushExternalRepository(string $remote = Git::DEFAULT_REMOTE, string $upstreamBranch = Git::DEFAULT_BRANCH, bool $dryRun = false): void
    {

        $backupBranch = sprintf('%s-backup', $this->getBranch());
        $this->getGit()->branch($backupBranch);
        $this->getGit()->fetch($remote);
        try {
            $this->getGit()->clean('-dfx', '.');
            $this->getGit()->merge($upstreamBranch, '--allow-unrelated-histories');
        } catch (GitException $e) {
            // Fix merge conflicts in a dumb way.
            $this->getGit()->commit('Fix merge conflicts.');
        }

        $this->log()->notice('Restore content from backed up branch...');
        $this->getGit()->checkout($backupBranch, '.');
        $this->getGit()->commit('Restore content from converted branch.');

        $this->log()->notice('Cleaning up gitignored files...');
        $this->getGit()->remove('-r', '--cached', '.');
        $this->getGit()->commit('Cleanup gitignored files.');

        $this->log()->notice('Deleting unused files from the old branch...');
        $diffFilesString = $this->getGit()->diff($backupBranch, $this->getBranch(), '--name-only', '--diff-filter=A');
        file_put_contents('/tmp/diff-files.txt', $diffFilesString);
        $this->getGit()->remove('--pathspec-from-file=/tmp/diff-files.txt');
        $this->getGit()->commit('Cleanup now unused files.');

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', $this->getBranch()));
        if ($dryRun) {
            $this->log()->notice('Dry run, not pushing changes.');
            return;
        }
        $this->getGit()->pushToRemote($remote, $this->getBranch(), '-f');
    }

    /**
     * Returns TRUE if two repositories' branches have a common history.
     *
     * @param string $repo1Remote
     *   Repository #1 remote name.
     * @param string $repo2Remote
     *   Repository #2 remote name. Defaults to "origin".
     * @param string $repo1Branch
     *   Repository #1 branch name. Defaults to "master".
     * @param string $repo2Branch
     *   Repository #2 branch name. Defaults to "master".
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function areGitReposWithCommonCommits(
        string $repo1Remote,
        string $repo2Remote = Git::DEFAULT_REMOTE,
        string $repo1Branch = Git::DEFAULT_BRANCH,
        string $repo2Branch = Git::DEFAULT_BRANCH
    ): bool {
        $this->getGit()->fetch($repo1Remote);
        $repo1CommitHashes = $this->getGit()->getCommitHashes(
            sprintf('%s/%s', $repo1Remote, $repo1Branch)
        );

        $this->getGit()->fetch($repo2Remote);
        $repo2CommitHashes = $this->getGit()->getCommitHashes(
            sprintf('%s/%s', $repo2Remote, $repo2Branch)
        );

        $identicalCommitHashes = array_intersect($repo2CommitHashes, $repo1CommitHashes);

        return 0 < count($identicalCommitHashes);
    }

    /**
     * Adds a commit to trigger a Pantheon's Integrated Composer build.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    protected function addCommitToTriggerBuild(): void
    {
        $this->log()->notice('Adding a comment to pantheon.yml to trigger a build...');

        $pantheonYmlFile = fopen(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'), 'a');
        fwrite($pantheonYmlFile, PHP_EOL . '# comment to trigger a Pantheon IC build');
        fclose($pantheonYmlFile);

        $this->getGit()->commit('Trigger Pantheon build');
        $this->getGit()->push($this->getBranch());

        $this->log()->notice('A comment has been added.');
    }

    /**
     * Sets the Site object by the site ID.
     *
     * @param string $siteId
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setSite(string $siteId): void
    {
        $this->site = $this->getSite($siteId);
    }

    /**
     * Returns the Site object.
     *
     * @return \Pantheon\Terminus\Models\Site
     */
    protected function site(): Site
    {
        return $this->site;
    }

    /**
     * Returns the LocalMachineHelper.
     *
     * @return \Pantheon\Terminus\Helpers\LocalMachineHelper
     *
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getLocalMachineHelper(): LocalMachineHelper
    {
        if (isset($this->localMachineHelper)) {
            return $this->localMachineHelper;
        }

        $this->localMachineHelper = $this->getContainer()->get(LocalMachineHelper::class);

        return $this->localMachineHelper;
    }

    /**
     * Deletes the target multidev environment and associated git branch if exists.
     *
     * @param string $branch
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException
     */
    private function deleteMultidevIfExists(string $branch): void
    {
        try {
            /** @var \Pantheon\Terminus\Models\Environment $multidev */
            $multidev = $this->site->getEnvironments()->get($branch);
            if (!$this->input()->getOption('yes') && !$this->io()
                    ->confirm(
                        sprintf(
                            <<<EOD
Multidev "%s" already exists. Are you sure you want to delete it and its source git branch?
EOD,
                            $branch
                        )
                    )
            ) {
                throw new TerminusCancelOperationException(
                    sprintf('Delete multidev "%s" operation has not been confirmed.', $branch)
                );
            }

            $this->log()->notice(
                sprintf('Deleting "%s" multidev environment and associated git branch...', $branch)
            );
            $workflow = $multidev->delete(['delete_branch' => true]);
            $this->processWorkflow($workflow);
        } catch (TerminusNotFoundException $e) {
            if (!$this->getGit()->isRemoteBranchExists($branch)) {
                return;
            }

            if (!$this->input()->getOption('yes')
                && !$this->io()->confirm(
                    sprintf(
                        'The git branch "%s" already exists. Are you sure you want to delete it?',
                        $branch
                    )
                )
            ) {
                throw new TerminusCancelOperationException(
                    sprintf('Delete git branch "%s" operation has not been confirmed.', $branch)
                );
            }

            $this->getGit()->deleteRemoteBranch($branch);
        }
    }

    /**
     * Determines whether the current site is a build tools site or not by looking at latest commit.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function isBuildToolsSite(): bool
    {
        $files = $this->getGit()->diffFileList('HEAD^1', 'HEAD');
        return in_array('build-metadata.json', $files);
    }

    /**
     * Get external vcs from build-metadata file.
     *
     * @return string|void External VCS url if found, null otherwise.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function getExternalVcsUrl(): ?string
    {
        $buildMetadataFile = Files::buildPath($this->getLocalSitePath(), 'build-metadata.json');
        $buildMetadataContent = json_decode(file_get_contents($buildMetadataFile), true);
        return $buildMetadataContent['url'] ?? null;
    }

    /**
     * Returns the terminus executable.
     *
     * @return string
     */
    protected function getTerminusExecutable(): string
    {
        if (isset($this->terminusExecutable)) {
            return $this->terminusExecutable;
        }

        if (getenv('LANDO_APP_NAME')) {
            // Lando-based environment.
            return $this->terminusExecutable = 'lando terminus';
        }

        return $this->terminusExecutable = 'terminus';
    }

    /**
     * Determines whether a multidev named "conversion" exists for this site.
     */
    protected function isConversionMultidevExist(): bool
    {
        $environments = $this->site()->getEnvironments()->fetch()->ids();
        return in_array('conversion', $environments, true);
    }

    /**
     * Determines whether the current site is a drupal-composer-managed site or not.
     */
    protected function isDrupalComposerManagedSite(): bool
    {
        $localPath = $this->getLocalSitePath(false);
        $upstreamConfScriptsFilePath = Files::buildPath($localPath, 'upstream-configuration', 'scripts', 'ComposerScripts.php');
        if (!is_file($upstreamConfScriptsFilePath)) {
            return false;
        }

        // Repository contents matches "drupal-recommended" upstream.
        $this->getGit()->addRemote(
            self::DRUPAL_TARGET_GIT_REMOTE_URL,
            self::DRUPAL_TARGET_UPSTREAM_ID
        );
        return $this->areGitReposWithCommonCommits(self::DRUPAL_TARGET_UPSTREAM_ID, Git::DEFAULT_REMOTE, 'main');
    }

    /**
     * Determines whether the current site is a drupal-recommended site or not.
     */
    protected function isDrupalRecommendedSite(): bool
    {
        $localPath = $this->getLocalSitePath(false);
        $upstreamConfComposerJsonPath = Files::buildPath($localPath, 'upstream-configuration', 'composer.json');
        if (!is_file($upstreamConfComposerJsonPath)) {
            return false;
        }

        $composerJsonContent = file_get_contents($upstreamConfComposerJsonPath);
        if (false !== strpos($composerJsonContent, 'drupal/core-recommended')) {
            return false;
        }

        // Repository contents matches "drupal-recommended" upstream.
        $this->getGit()->addRemote(
            self::DRUPAL_RECOMMENDED_GIT_REMOTE_URL,
            self::DRUPAL_RECOMMENDED_UPSTREAM_ID
        );
        return $this->areGitReposWithCommonCommits(self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
    }

    /**
     * Determines whether the current site is a drupal-project site or not.
     */
    protected function isDrupalProjectSite(): bool
    {
        if (!$this->isDrupalRecommendedSite()) {
            $localPath = $this->getLocalSitePath(false);
            $upstreamConfComposerJsonPath = Files::buildPath($localPath, 'upstream-configuration', 'composer.json');
            return is_file($upstreamConfComposerJsonPath);
        }
        return false;
    }

    /**
     * Wait for sync_code workflow to finish.
     */
    protected function waitForSyncCodeWorkflow(string $environment): void
    {
        $workflows = $this->site->getWorkflows();
        $workflows->reset();
        $workflowItems = $workflows->fetch(['paged' => false,])->all();
        $workflowToSearch = 'sync_code';
        foreach (array_slice($workflowItems, 0, 5) as $workflowItem) {
            $workflowType = str_replace('"', '', $workflowItem->get('type'));
            $firstWorkflowType = $firstWorkflowType ?? $workflowType;
            if (strpos($workflowType, $workflowToSearch) !== false && $workflowItem->get('environment_id') === $environment) {
                $this->processWorkflow($workflowItem);
                return;
            }
        }

        $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'. Giving up searching for the right workflow.", ['current' => $firstWorkflowType, 'expected' => $workflowToSearch]);
    }
}
