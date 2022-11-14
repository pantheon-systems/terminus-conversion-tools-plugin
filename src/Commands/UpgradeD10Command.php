<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;
use Pantheon\TerminusConversionTools\Utils\Files;
use Composer\Semver\Comparator;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\MigrateComposerJsonTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;

/**
 * Class UpgradeD10Command.
 */
class UpgradeD10Command extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;
    use MigrateComposerJsonTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    private const DRUPAL_TARGET_UPSTREAM_ID = 'drupal-composer-managed';
    private const DRUPAL_TARGET_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-composer-managed.git';

    /**
     * Upgrade a Drupal 9 with IC site to Drupal 10.
     *
     * @command conversion:upgrade-d10
     *
     * @option branch The target branch name for multidev env.
     * @option skip-upgrade-status Skip upgrade status checks.
     * @option dry-run Skip creating multidev and pushing the branch.
     * @option run-updb Run `drush updb` after conversion.
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on.
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function upgradeToD10(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'skip-upgrade-status' => false,
            'dry-run' => false,
            'run-updb' => true,
            'run-cr' => true,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);
        $localSitePath = $this->getLocalSitePath();
        $this->setGit($localSitePath);
        $this->setComposer($localSitePath);

        if (!$this->isDrupalRecommendedSite()
            && !$this->isDrupalProjectSite()
            && !$this->isDrupalComposerManagedSite()) {
            $this->log()->warning(
                <<<EOD
Site does not seem to match the expected upstream repos:

    https://github.com/pantheon-systems/drupal-composer-managed or
    https://github.com/pantheon-systems/drupal-recommended or
    https://github.com/pantheon-systems/drupal-project
EOD
            );
        }

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'));
        if (file_exists(Files::buildPath($this->getLocalSitePath(), 'pantheon.upstream.yml'))) {
            $pantheonYmlContent = array_merge(
                $pantheonYmlContent,
                Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.upstream.yml'))
            );
        }
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
        if (Comparator::greaterThanOrEqualTo($drupalCoreVersion, '^10')) {
            throw new TerminusException(
                'Site {site_name} is not Drupal 9. It may have been already upgraded to Drupal 10',
                [
                    'site_name' => $this->site()->getName(),
                ]
            );
        }

        if (!$options['skip-upgrade-status']) {
            $this->log()->notice('Checking if site is ready for upgrade to Drupal 10');
            $command = 'upgrade_status:analyze --all';
            $result = $this->runDrushCommand($command, 'dev');

            if (0 !== $result['exit_code']) {
                throw new TerminusException(
                    'Upgrade status command not found or not successful. Error: {error}',
                    [
                        'error' => $result['stderr'],
                    ]
                );
            }
        }

        $masterBranch = Git::DEFAULT_BRANCH;
        $this->getGit()->checkout('-b', $this->getBranch(), Git::DEFAULT_REMOTE . '/' . $masterBranch);

        // Phpstan was included in Drupal 10, so it needs to be allowed.
        $this->getComposer()->config('--no-plugins', 'allow-plugins.phpstan/extension-installer', 'true');

        foreach ($this->getDrupalComposerDependencies('^10') as $dependency) {
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

        $this->setPhpVersion($this->getLocalSitePath(), 8.1);

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
            $this->executeDrushDatabaseUpdates($options);
            $this->executeDrushCacheRebuild($options);
            $this->addCommitToTriggerBuild();
        } else {
            $this->log()->warning('Push to multidev has been skipped.');
        }

        $this->log()->notice(sprintf('Site %s has been upgraded to Drupal 10', $this->site()->getName()));

        $this->log()->notice('Done!');
    }
}
