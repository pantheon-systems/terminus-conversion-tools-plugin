<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\TerminusModel;

/**
 * Trait ConversionCommandsTrait.
 *
 * @method log()
 * @method getContainer()
 * @method processWorkflow(\Pantheon\Terminus\Models\Workflow $workflow)
 */
trait ConversionCommandsTrait
{
    /**
     * @var \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private $localMachineHelper;

    /**
     * Clones the site repository to local machine and return the absolute path to the local copy.
     *
     * @param \Pantheon\Terminus\Models\Site $site
     * @param string $siteDirName
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function cloneSiteGitRepository(Site $site, string $siteDirName): string
    {
        $path = $site->getLocalCopyDir($siteDirName);
        $this->log()->notice(
            sprintf('Cloning %s site repository into "%s"...', $site->getName(), $path)
        );

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $site->getEnvironments()->get('dev');

        $gitUrl = $devEnv->connectionInfo()['git_url'] ?? null;
        $this->getLocalMachineHelper()->cloneGitRepository($gitUrl, $path, true);

        return $path;
    }

    /**
     * Returns the LocalMachineHelper.
     *
     * @return \Pantheon\Terminus\Helpers\LocalMachineHelper
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
     * @param \Pantheon\Terminus\Models\Site $site
     * @param string $branch
     *
     * @return \Pantheon\Terminus\Models\Environment
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function createMultidev(Site $site, string $branch): TerminusModel
    {
        $this->log()->notice(sprintf('Creating "%s" multidev environment...', $branch));

        /** @var \Pantheon\Terminus\Models\Environment $devEnv */
        $devEnv = $site->getEnvironments()->get('dev');

        $workflow = $site->getEnvironments()->create($branch, $devEnv);
        $this->processWorkflow($workflow);
        $site->unsetEnvironments();

        return $site->getEnvironments()->get($branch);
    }
}
