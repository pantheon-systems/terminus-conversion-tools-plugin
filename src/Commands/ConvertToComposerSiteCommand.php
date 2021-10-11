<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Helpers\LocalMachineHelper;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Models\Site;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    private const TARGET_GIT_BRANCH = 'composerify';
    private const IC_GIT_REMOTE_NAME = 'ic';
    private const IC_GIT_REMOTE_URL = 'git@github.com:pantheon-upstreams/drupal-project.git';
    private const COMPOSER_DRUPAL_PACKAGE_NAME = 'drupal/core-recommended';
    private const COMPOSER_DRUPAL_PACKAGE_VERSION = '^8.9';

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

        if ('drupal8' !== $site->getUpstream()->get('machine_name')) {
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

        $this->createMultidev($site, $env);
        $this->addComposerPackages($contribProjects);
        $this->copyCustomProjects($customProjectsDirs);
        $this->addCommitToTriggerBuild();

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $this->git->push(self::TARGET_GIT_BRANCH);
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
        $this->log()->notice(sprintf('Detecting custom modules and themes in "%s"...', $this->localPath));
        $customProjectsDirs = $this->drupal8ComponentsDetector->getCustomProjectDirectories();
        foreach ($customProjectsDirs as $dir) {
            $this->log()->notice(sprintf('"%s" directory found', $dir));
        }

        return $customProjectsDirs;
    }

    /**
     * Creates the target local git branch based on Pantheon's "drupal-project" upstream.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function createLocalGitBranch(): void
    {
        $this->log()->notice('Creating "%s" branch based on "drupal-project" upstream...');
        $this->git->addRemote(self::IC_GIT_REMOTE_URL, self::IC_GIT_REMOTE_NAME);
        $this->git->fetch(self::IC_GIT_REMOTE_NAME);
        $this->git->checkout(sprintf('--no-track -b %s %s/master', self::TARGET_GIT_BRANCH, self::IC_GIT_REMOTE_NAME));
    }

    /**
     * Copies configuration files.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copyConfigurationFiles(): void
    {
        $this->log()->notice('Copying configuration files...');
        $this->git->checkout('master sites/default/config');
        $this->git->move(sprintf('%s/sites/default/config/* %s/config', $this->localPath, $this->localPath));
        $this->git->remove(sprintf('-f %s/sites/default/config/.htaccess', $this->localPath));
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
        $this->git->checkout('master pantheon.yml');
        $this->git->commit('Copy pantheon.yml');

        $pantheonYmlContent = Yaml::parseFile($this->localPath . DIRECTORY_SEPARATOR . 'pantheon.yml');
        if (isset($pantheonYmlContent['build_step']) && true === $pantheonYmlContent['build_step']) {
            return;
        }

        $pantheonYmlContent['build_step'] = true;
        $pantheonYmlFile = fopen($this->localPath . DIRECTORY_SEPARATOR . 'pantheon.yml', 'wa+');
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
            // @todo: check the result of the workflow.
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
     * @param \Pantheon\Terminus\Models\Environment $env
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function createMultidev(Site $site, Environment $env): void
    {
        $this->log()->notice(sprintf('Creating "%s" multidev environment...', self::TARGET_GIT_BRANCH));
        $workflow = $site->getEnvironments()->create(self::TARGET_GIT_BRANCH, $env);
        // @todo: check the result of the workflow.
        $this->processWorkflow($workflow);
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
        $this->log()->notice('Packages have been added to Composer');
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
        if (0 === count($customProjectsDirs)) {
            $this->log()->notice('No custom projects (modules and themes) to copy');

            return;
        }

        $this->log()->notice('Copying custom projects (modules and themes)...');
        foreach ($customProjectsDirs as $relativePath => $absolutePath) {
            // @todo: refactor.
            // @todo: use DIRECTORY_SEPARATOR
            $targetPath = sprintf('%s/web/modules/custom', $this->localPath);
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
            $this->git->checkout(sprintf('master %s', $relativePath));
            // @todo: separate modules and themes
            $this->git->move(sprintf('%s/* %s', $absolutePath, $targetPath));
            if ($this->git->isAnythingToCommit()) {
                $this->git->commit(sprintf('Copy custom projects from %s', $relativePath));
            }
        }
    }

    /**
     * Adds a commit to trigger a Pantheon's Integrated Composer build.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function addCommitToTriggerBuild(): void
    {
        $this->log()->notice('Adding comment to pantheon.upstream.yml to trigger a build...');
        // @todo: create a method for updating a file.
        $pantheonUpstreamYml = fopen($this->localPath . DIRECTORY_SEPARATOR . 'pantheon.upstream.yml', 'a');
        fwrite($pantheonUpstreamYml, PHP_EOL . '# add a comment to trigger a change and build');
        fclose($pantheonUpstreamYml);
        $this->git->commit('Trigger Pantheon build');
    }
}
