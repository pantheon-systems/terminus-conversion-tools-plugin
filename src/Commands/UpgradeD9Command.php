<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;
use Pantheon\TerminusConversionTools\Utils\Files;
use Composer\Semver\Semver;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\MigrateComposerJsonTrait;

/**
 * Class UpgradeD9Command.
 */
class UpgradeD9Command extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;
    use MigrateComposerJsonTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    /**
     * Upgrade a Drupal 8 with IC site to Drupal 9.
     *
     * @command conversion:upgrade-d9
     *
     * @option branch The target branch name for multidev env.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function upgradeToD9(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'skip-upgrade-status' => false,
            'dry-run' => false,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);
        $localSitePath = $this->getLocalSitePath();
        $this->setGit($localSitePath);
        $this->setComposer($localSitePath);

        if (!$this->isDrupalRecommendedSite() && !$this->isDrupalProjectSite()) {
            $this->log()->warning(
                <<<EOD
Site does not seem to match the expected upstream repos:

    https://github.com/pantheon-systems/drupal-recommended or
    https://github.com/pantheon-systems/drupal-project
EOD);
        }

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'));
        if (false === ($pantheonYmlContent['build_step'] ?? false)) {
            throw new TerminusException(
                'Pantheon Integrated Composer feature is not enabled on the site {site_name}.',
                [
                    'site_name' => $this->site()->getName(),
                ]
            );
        }

        $this->sourceComposerJson = $this->getComposerJson();
        $drupalPackage = $this->sourceHasDrupalCoreRecommended() ? 'drupal/core-recommended' : 'drupal/core';
        $drupalCoreVersion = $this->sourceComposerJson['require'][$drupalPackage];

        // @todo: Does this work for composer.json?
        if (!Semver::satisfies($drupalCoreVersion, '^8')) {
            throw new TerminusException(
                'Site {site_name} is not Drupal 8. It may have been already upgraded to Drupal 9',
                [
                    'site_name' => $this->site()->getName(),
                ]
            );
        }

        if (!$options['skip-upgrade-status']) {
            // @todo Step 1: Does this site has upgrade-status? Bail if not! (skip option)
            // Look at sendCommandViaSsh in Terminus.
            // @todo Step 2: Run update status. Is it clean? Bail if not! (skip option)
            $this->log()->notice('Checking if site is ready for upgrade to Drupal 9');
            //$this->checkSiteIsReadyForUpgrade();
        }

        $masterBranch = Git::DEFAULT_BRANCH;
        $this->getGit()->checkout('-b', $this->getBranch(), Git::DEFAULT_REMOTE . '/' . $masterBranch);

        foreach ($this->getDrupalComposerDependencies('^9') as $dependency) {
            $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
            if ($dependency['is_dev']) {
                $arguments[] = '--dev';
            }

            $this->getComposer()->require(...$arguments);
            if ($this->getGit()->isAnythingToCommit()) {
                $this->getGit()->commit(
                    sprintf('Add %s (%s) project to Composer', $dependency['package'], $dependency['version'])
                );
                $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
            }
        }
        $this->getComposer()->update();
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Run composer update.');
            $this->log()->notice('Composer update has been executed.');
        }

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
        } else {
            $this->log()->warning('Push to multidev has been skipped.');
        }

        $this->log()->notice(sprintf('Site %s has been upgraded to Drupal 9', $this->site()->getName()));

        $this->log()->notice('Done!');
    }

}
