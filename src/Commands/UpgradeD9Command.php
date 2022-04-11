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
use Pantheon\Terminus\Helpers\LocalMachineHelper;

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
        if (file_exists(Files::buildPath($this->getLocalSitePath(), 'pantheon.upstream.yml'))) {
            $pantheonYmlContent = array_merge($pantheonYmlContent, Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.upstream.yml')));
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
        if (Comparator::greaterThanOrEqualTo($drupalCoreVersion, '^9')) {
            throw new TerminusException(
                'Site {site_name} is not Drupal 8. It may have been already upgraded to Drupal 9',
                [
                    'site_name' => $this->site()->getName(),
                ]
            );
        }

        if (!$options['skip-upgrade-status']) {
            $this->log()->notice('Checking if site is ready for upgrade to Drupal 9');
            $command = 'drush upgrade_status:analyze --all';
            $ssh_command = $this->getConnectionString() . ' ' . escapeshellarg($command);
            $this->logger->debug('shell command: {command}', [ 'command' => $command ]);
            $result = $this->getContainer()->get(LocalMachineHelper::class)->exec($ssh_command);
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
        $this->requireUpdatedPackages();
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

    /**
     * Require updated packages from a fixed list if they are installed in current site.
     */
    protected function requireUpdatedPackages() {
        $packages = [
            [
                'package' => 'phpunit/phpunit',
                'version' => '^8',
            ],
        ];
        $composerJson = $this->getComposer()->getComposerJsonData();
        foreach ($packages as $package) {
            if (isset($composerJson['require'][$package['package']])) {
                $this->getComposer()->require($package['package'], $package['version'], '--no-update');
                $this->getGit()->commit(
                    sprintf('Add %s (%s) project to Composer', $package['package'], $package['version'])
                );
                $this->log()->notice(sprintf('%s (%s) is added', $package['package'], $package['version']));
            } elseif (isset($composerJson['require-dev'][$package['package']])) {
                $this->getComposer()->require($package['package'], $package['version'], '--dev', '--no-update');
                $this->getGit()->commit(
                    sprintf('Add %s (%s) project to Composer', $package['package'], $package['version'])
                );
                $this->log()->notice(sprintf('%s (%s) is added', $package['package'], $package['version']));
            }
        }
    }

    /**
     * Returns the connection string.
     *
     * @return string
     *   SSH connection string.
     */
    private function getConnectionString()
    {
        $environment = $this->getEnv(sprintf('%s.dev', $this->site()->getName()));
        $sftp = $environment->sftpConnectionInfo();
        $command = $this->getConfig()->get('ssh_command');

        return vsprintf(
            '%s -T %s@%s -p %s -o "StrictHostKeyChecking=no" -o "AddressFamily inet"',
            [$command, $sftp['username'], $sftp['host'], $sftp['port'],]
        );
    }

}
