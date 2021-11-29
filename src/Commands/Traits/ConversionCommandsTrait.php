<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\TerminusModel;
use Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Trait ConversionCommandsTrait.
 */
trait ConversionCommandsTrait
{
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
     * @var \Pantheon\TerminusConversionTools\Utils\Git
     */
    private Git $git;

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
    private function cloneSiteGitRepository(bool $force = true): string
    {
        $siteDirName = sprintf('%s_composer_conversion', $this->site->getName());
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
     * Creates the multidev environment.
     *
     * @param string $branch
     *
     * @return \Pantheon\Terminus\Models\Environment
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function createMultidev(string $branch): TerminusModel
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
    private function switchUpstream(string $upstreamId): void
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
    private function getBackupBranchName(): string
    {
        $backupBranchNamePrefix = 'mstr-bkp-';

        // Check if the backup branch already exists.
        foreach (array_keys($this->getSupportedSourceUpstreamIds()) as $upstreamAlias) {
            $backupBranchName = $backupBranchNamePrefix . $upstreamAlias;
            if ($this->git->isRemoteBranchExists($backupBranchName)) {
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
    private function getSupportedSourceUpstreamIds(): array
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
    private function getSourceUpstreamIdByBackupBranchName(string $backupBranchName): string
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
            if ($this->git->isRemoteBranchExists($branch)) {
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

                $this->git->deleteRemoteBranch($branch);
            }
        }
    }

    /**
     * Creates the target local git branch based on Pantheon's "drupal-recommended" upstream.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function createLocalGitBranch(): void
    {
        $this->log()->notice(
            sprintf('Creating "%s" git branch based on "drupal-recommended" upstream...', $this->branch)
        );
        $this->git->addRemote(self::TARGET_UPSTREAM_GIT_REMOTE_URL, self::TARGET_UPSTREAM_GIT_REMOTE_NAME);
        $this->git->fetch(self::TARGET_UPSTREAM_GIT_REMOTE_NAME);
        $this->git->checkout(
            '--no-track',
            '-b',
            $this->branch,
            self::TARGET_UPSTREAM_GIT_REMOTE_NAME . '/' . Git::DEFAULT_BRANCH
        );
    }
}
