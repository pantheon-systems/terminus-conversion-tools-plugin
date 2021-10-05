<?php

namespace Pantheon\TerminusConversionTools\Commands;

use League\Container\ContainerAwareTrait;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ContainerAwareTrait;

    /**
     * @var \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private $localMachineHelper;

    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function convert(string $site_id)
    {
        $site = $this->getSite($site_id);

        if (!$site->getFramework()->isDrupal8Framework()) {
            throw new TerminusException(
                'The site {site_name} is not a Drupal 8 based site.',
                ['site_name' => $site->getName()]
            );
        }

        if (!$site->getUpstream()->isDrops8Upstream()) {
            throw new TerminusException(
                'The site {site_name} is not a "drops-8" upstream based site.',
                ['site_name' => $site->getName()]
            );
        }

        /** @var \Pantheon\Terminus\Models\Environment $env */
        $env = $site->getEnvironments()->get('dev');

        $sourceSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_source', $site->getName())
        );
        $drupal8ComponentsDetector = new Drupal8Projects($this->getContainer());
        $projects = $drupal8ComponentsDetector->getContribProjects($sourceSitePath);
        print_r($projects);

        $destinationSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_destination', $site->getName())
        );
    }

    /**
     * Clones the site repository to local machine and return the absolute path to the local copy.
     *
     * @param \Pantheon\Terminus\Models\Site $site
     * @param \Pantheon\Terminus\Models\Environment $env
     * @param $siteDirName
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function cloneSiteGitRepository(Site $site, Environment $env, $siteDirName): string
    {
        $path = $site->getLocalCopyDir($siteDirName);
        $this->log()->notice(
            sprintf('Cloning %s site repository into "%s"...', $site->getName(), $path)
        );
        $gitUrl = $env->connectionInfo()['git_url'] ?? null;
        $this->getLocalMachineHelper()->cloneGitRepository($gitUrl, $path, true);
        $this->log()->notice(
            sprintf('The %s site repository has been cloned into "%s"', $site->getName(), $path)
        );

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
}
