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
     * Clones the site repository to local machine and return the absolute path to the local copy.
     *
     * @param bool $force
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    protected function cloneSiteGitRepository(bool $force = true): string
    {
        $siteDirName = sprintf('%s_terminus_conversion_plugin', $this->site->getName());
        $path = $this->site->getLocalCopyDir($siteDirName);
        if (!$force && 2 < count(scandir($path))) {
            return $path;
        }

        $this->log()->notice(
            sprintf('Cloning %s site repository into "%s"...', $this->site->getName(), $path)
        );

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $this->site->getEnvironments()->get('dev');

        $gitUrl = $devEnv->connectionInfo()['git_url'] ?? null;
        $this->getLocalMachineHelper()->cloneGitRepository($gitUrl, $path, true);

        return $path;
    }

    /**
     * Clones the site and returns the path to the local site copy.
     *
     * @param null|bool $force
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    protected function getLocalSitePath(?bool $force = null)
    {
        if (true !== $force && isset($this->localSitePath)) {
            return $this->localSitePath;
        }

        $this->localSitePath = null === $force
            ? $this->cloneSiteGitRepository()
            : $this->cloneSiteGitRepository($force);

        return $this->localSitePath;
    }

    /**
     * Returns root composer.json file contents.
     *
     * @param string|null $filePath
     *
     * @return array
     *
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
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    protected function createLocalGitBranchFromRemote(string $remoteUrl): string
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
            $targetGitRemoteName . '/' . Git::DEFAULT_BRANCH
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
                            'Multidev "%s" already exists. Are you sure you want to delete it and its source git branch?',
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
    protected function isBuildToolsSite()
    {
        $files = $this->getGit()->diffFileList('HEAD^1', 'HEAD');
        return in_array('build-metadata.json', $files);
    }
}
