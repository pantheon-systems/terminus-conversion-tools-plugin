<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Models\TerminusModel;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    private const DROPS_8_UPSTREAM_ID = 'drupal8';
    private const TARGET_GIT_BRANCH = 'composerify';
    private const IC_GIT_REMOTE_NAME = 'ic';
    private const IC_GIT_REMOTE_URL = 'git@github.com:pantheon-upstreams/drupal-project.git';
    private const COMPOSER_DRUPAL_PACKAGE_NAME = 'drupal/core-recommended';
    private const COMPOSER_DRUPAL_PACKAGE_VERSION = '^8.9';
    private const MODULES_SUBDIR = 'modules';
    private const THEMES_SUBDIR = 'themes';

    /**
     * @var \Pantheon\Terminus\Helpers\LocalMachineHelper
     */
    private $localMachineHelper;

    /**
     * @var string
     */
    private string $localPath;

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Drupal8Projects
     */
    private Drupal8Projects $drupal8ComponentsDetector;

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Git
     */
    private Git $git;

    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
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

        if (self::DROPS_8_UPSTREAM_ID !== $site->getUpstream()->get('machine_name')) {
            throw new TerminusException(
                'The site {site_name} is not a "drops-8" upstream based site.',
                ['site_name' => $site->getName()]
            );
        }

        /** @var \Pantheon\Terminus\Models\Environment $env */
        $env = $site->getEnvironments()->get('dev');

        $this->localPath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_composer_conversion', $site->getName())
        );
        $this->drupal8ComponentsDetector = new Drupal8Projects($this->localPath);
        $this->git = new Git($this->localPath);

        $contribProjects = $this->getContribDrupal8Projects();
        $customProjectsDirs = $this->getCustomProjectsDirectories();

        $this->createLocalGitBranch();
        $this->copyConfigurationFiles();
        $this->copyPantheonYml();

        $this->deleteMultidevIfExists($site);

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $this->git->push(self::TARGET_GIT_BRANCH);

        $mdEnv = $this->createMultidev($site, $env);
        $this->addComposerPackages($contribProjects);
        $this->copyCustomProjects($customProjectsDirs);
        $this->copySettingsPhp();
        $this->addCommitToTriggerBuild();

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $this->git->push(self::TARGET_GIT_BRANCH);

        $this->log()->notice(
            sprintf('Link to "%s" multidev environment dashboard: %s', self::TARGET_GIT_BRANCH, $mdEnv->dashboardUrl())
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
     * Detects and returns the list of contrib Drupal8 projects (modules and themes).
     *
     * @return array
     */
    private function getContribDrupal8Projects(): array
    {
        $this->log()->notice(sprintf('Detecting contrib modules and themes in "%s"...', $this->localPath));
        $projects = $this->drupal8ComponentsDetector->getContribProjects();
        if (0 === count($projects)) {
            $this->log()->notice(sprintf('No contrib modules or themes were detected in "%s"', $this->localPath));

            return [];
        }

        $projectsInline = array_map(
            fn($project) => sprintf('%s (%s)', $project['name'], $project['version']),
            $projects
        );
        $this->log()->notice(
            sprintf(
                '%d contrib modules and/or themes are detected: %s',
                count($projects),
                implode(', ', $projectsInline)
            )
        );

        return $projects;
    }

    /**
     * Returns the list directories of custom projects.
     *
     * @return array
     */
    private function getCustomProjectsDirectories(): array
    {
        $this->log()->notice(sprintf('Detecting custom projects (modules and themes) in "%s"...', $this->localPath));
        $customModuleDirs = $this->drupal8ComponentsDetector->getCustomModuleDirectories();
        foreach ($customModuleDirs as $path) {
            $this->log()->notice(sprintf('Custom modules found in "%s"', $path));
        }
        $customThemeDirs = $this->drupal8ComponentsDetector->getCustomThemeDirectories();
        foreach ($customThemeDirs as $path) {
            $this->log()->notice(sprintf('Custom themes found in "%s"', $path));
        }

        return [
            self::MODULES_SUBDIR => $customModuleDirs,
            self::THEMES_SUBDIR => $customThemeDirs,
        ];
    }

    /**
     * Creates the target local git branch based on Pantheon's "drupal-project" upstream.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function createLocalGitBranch(): void
    {
        $this->log()->notice(
            sprintf('Creating "%s" branch based on "drupal-project" upstream...', self::TARGET_GIT_BRANCH)
        );
        $this->git->addRemote(self::IC_GIT_REMOTE_URL, self::IC_GIT_REMOTE_NAME);
        $this->git->fetch(self::IC_GIT_REMOTE_NAME);
        $this->git->checkout('--no-track', '-b', self::TARGET_GIT_BRANCH, self::IC_GIT_REMOTE_NAME . '/master');
    }

    /**
     * Copies configuration files.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copyConfigurationFiles(): void
    {
        $this->log()->notice('Copying configuration files...');
        $this->git->checkout('master', Files::buildPath('sites', 'default', 'config'));
        $sourcePath = Files::buildPath($this->localPath, 'sites', 'default', 'config');
        $destinationPath = Files::buildPath($this->localPath, 'config');
        $this->git->move(sprintf('%s%s*', $sourcePath, DIRECTORY_SEPARATOR), $destinationPath);

        $htaccessFile = Files::buildPath('sites', 'default', 'config', '.htaccess');
        $this->git->remove('-f', $htaccessFile);

        if ($this->git->isAnythingToCommit()) {
            $this->git->commit('Pull in configuration from default branch');
        } else {
            $this->log()->notice('No configuration files found');
        }
    }

    /**
     * Copies pantheon.yml file and sets the "build_step" flag.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copyPantheonYml(): void
    {
        $this->log()->notice('Copying pantheon.yml file...');
        $this->git->checkout('master', 'pantheon.yml');
        $this->git->commit('Copy pantheon.yml');

        $path = Files::buildPath($this->localPath, 'pantheon.yml');
        $pantheonYmlContent = Yaml::parseFile($path);
        if (isset($pantheonYmlContent['build_step']) && true === $pantheonYmlContent['build_step']) {
            return;
        }

        $pantheonYmlContent['build_step'] = true;
        $pantheonYmlFile = fopen($path, 'wa+');
        fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent));
        fclose($pantheonYmlFile);

        $this->git->commit('Add build_step:true to pantheon.yml');
    }

    /**
     * Deletes the target multidev environment and associated git branch if exists.
     *
     * @param \Pantheon\Terminus\Models\Site $site
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function deleteMultidevIfExists(Site $site)
    {
        try {
            /** @var \Pantheon\Terminus\Models\Environment $multidev */
            $multidev = $site->getEnvironments()->get(self::TARGET_GIT_BRANCH);
            if (!$this->input()->getOption('yes')
                && !$this->io()
                    ->confirm(
                        sprintf(
                            'Multidev "%s" already exists. Are you sure you want to delete it and its source branch?',
                            self::TARGET_GIT_BRANCH
                        )
                    )
            ) {
                return;
            }

            $this->log()->notice(
                sprintf('Deleting "%s" multidev environment and associated git branch...', self::TARGET_GIT_BRANCH)
            );
            $workflow = $multidev->delete(['delete_branch' => true]);
            $this->processWorkflow($workflow);
        } catch (TerminusNotFoundException $e) {
            if ($this->git->isRemoteBranchExists(self::TARGET_GIT_BRANCH)) {
                if (!$this->input()->getOption('yes')
                    && !$this->io()->confirm(
                        sprintf(
                            'The branch "%s" already exists. Are you sure you want to delete it?',
                            self::TARGET_GIT_BRANCH
                        )
                    )
                ) {
                    return;
                }

                $this->git->deleteRemoteBranch(self::TARGET_GIT_BRANCH);
            }
        }
    }

    /**
     * Creates the target multidev environment.
     *
     * @param \Pantheon\Terminus\Models\Site $site
     * @param \Pantheon\Terminus\Models\Environment $sourceEnv
     *
     * @return \Pantheon\Terminus\Models\Environment
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function createMultidev(Site $site, Environment $sourceEnv): TerminusModel
    {
        $this->log()->notice(sprintf('Creating "%s" multidev environment...', self::TARGET_GIT_BRANCH));
        $workflow = $site->getEnvironments()->create(self::TARGET_GIT_BRANCH, $sourceEnv);
        $this->processWorkflow($workflow);
        $site->unsetEnvironments();

        return $site->getEnvironments()->get(self::TARGET_GIT_BRANCH);
    }

    /**
     * Adds dependencies to composer.json.
     *
     * @param array $contribProjects
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function addComposerPackages(array $contribProjects): void
    {
        $this->log()->notice('Adding packages to Composer...');
        $composer = new Composer($this->localPath);

        $composer->require(self::COMPOSER_DRUPAL_PACKAGE_NAME, self::COMPOSER_DRUPAL_PACKAGE_VERSION);
        $this->git->commit(
            sprintf(
                'Add %s (%s) project to Composer',
                self::COMPOSER_DRUPAL_PACKAGE_NAME,
                self::COMPOSER_DRUPAL_PACKAGE_VERSION
            )
        );
        $this->log()->notice(
            sprintf(
                '%s (%s) is added',
                self::COMPOSER_DRUPAL_PACKAGE_NAME,
                self::COMPOSER_DRUPAL_PACKAGE_VERSION
            )
        );

        foreach ($contribProjects as $project) {
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            $composer->require($packageName, $packageVersion);
            $this->git->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
            $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
        }
    }

    /**
     * Copies custom projects (modules and themes).
     *
     * @param array $customProjectsDirs
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copyCustomProjects(array $customProjectsDirs): void
    {
        if (0 === count(array_filter($customProjectsDirs))) {
            $this->log()->notice('No custom projects (modules and themes) to copy');

            return;
        }

        $this->log()->notice('Copying custom modules and themes...');
        foreach ($customProjectsDirs as $subDir => $dirs) {
            foreach ($dirs as $relativePath) {
                $this->git->checkout('master', $relativePath);
                $targetPath = Files::buildPath('web', $subDir, 'custom');

                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
                $this->git->move(sprintf('%s%s*', $relativePath, DIRECTORY_SEPARATOR), $targetPath);

                if ($this->git->isAnythingToCommit()) {
                    $this->git->commit(sprintf('Copy custom %s from %s', $subDir, $relativePath));
                    $this->log()->notice(sprintf('Copied custom %s from %s', $subDir, $relativePath));
                }
            }
        }
    }

    /**
     * Copies settings.php file.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copySettingsPhp(): void
    {
        $this->log()->notice('Copying settings.php file...');
        $this->git->checkout('master', Files::buildPath('sites', 'default', 'settings.php'));
        $this->git->move(
            Files::buildPath('sites', 'default', 'settings.php'),
            Files::buildPath('web', 'sites', 'default', 'settings.php'),
            '-f',
        );
        $this->git->commit('Copy settings.php');
    }

    /**
     * Adds a commit to trigger a Pantheon's Integrated Composer build.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function addCommitToTriggerBuild(): void
    {
        $this->log()->notice('Adding comment to pantheon.upstream.yml to trigger a build...');
        $path = Files::buildPath($this->localPath, 'pantheon.upstream.yml');
        $pantheonUpstreamYml = fopen($path, 'a');
        fwrite($pantheonUpstreamYml, PHP_EOL . '# add a comment to trigger a change and build');
        fclose($pantheonUpstreamYml);
        $this->git->commit('Trigger Pantheon build');
    }
}
