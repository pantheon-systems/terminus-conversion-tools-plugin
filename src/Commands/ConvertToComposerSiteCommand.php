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

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use WorkflowProcessingTrait;

    private const TARGET_GIT_BRANCH = 'composerify';
    private const COMPOSER_DRUPAL_PACKAGE_NAME = 'drupal/core-recommended';
    private const COMPOSER_DRUPAL_PACKAGE_VERSION = '^8.9';

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

        $sourceSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_source', $site->getName())
        );

        $this->log()->notice(sprintf('Detecting contrib modules and themes in %s...', $sourceSitePath));
        $drupal8ComponentsDetector = new Drupal8Projects($sourceSitePath);
        $projects = $drupal8ComponentsDetector->getContribProjects();
        if (0 < count($projects)) {
            $projectsInline = array_map(
                fn ($project) => sprintf('%s (%s)', $project['name'], $project['version']),
                $projects
            );

            $this->log()->notice(
                sprintf(
                    '%d contrib modules and/or themes are detected: %s',
                    count($projects),
                    implode(', ', $projectsInline)
                )
            );
        } else {
            // todo: do not stop, continue (core + custom modules left).
            $this->log()->notice(sprintf('No contrib modules or themes were detected in %s', $sourceSitePath));
        }

        $destinationSitePath = $this->cloneSiteGitRepository(
            $site,
            $env,
            sprintf('%s_destination', $site->getName())
        );

        // @todo: change notice to something that includes info about repo "https://github.com/pantheon-upstreams/drupal-project" add detailed messages for other operations.
        $this->log()->notice('Adding Pantheon drupal-project upstream...');
        $git = new Git($destinationSitePath);
        $git->executeGitCommand('remote add ic git@github.com:pantheon-upstreams/drupal-project.git');
        $git->executeGitCommand('fetch ic');
        $git->executeGitCommand(sprintf('checkout --no-track -b %s ic/master', self::TARGET_GIT_BRANCH));

        $this->log()->notice('Copying configuration files...');
        // Pull in the default configuration files.
        $git->executeGitCommand('checkout master sites/default/config');
        $git->executeGitCommand(sprintf('mv %s/sites/default/config/* %s/config', $destinationSitePath, $destinationSitePath));
        $git->executeGitCommand(sprintf('rm -f %s/sites/default/config/.htaccess', $destinationSitePath));
        // @todo: check if there is anything to commit
        $git->commit('Pull in configuration from default branch');

        $this->log()->notice('Copying pantheon.yml file...');
        // Copy pantheon.yml file.
        $git->executeGitCommand('checkout master pantheon.yml');
        $git->commit('Copy pantheon.yml');
        // @todo: check if build_step is already there.
        $pantheonYml = fopen($destinationSitePath . '/' . 'pantheon.yml', 'a');
        fwrite($pantheonYml, PHP_EOL . 'build_step: true');
        fclose($pantheonYml);
        $git->commit('Add build_step:true to pantheon.yml');

        $this->log()->notice(sprintf('Deleting "%s" multidev environment and associated git branch...', self::TARGET_GIT_BRANCH));
        try {
            /** @var \Pantheon\Terminus\Models\Environment $multidev */
            $multidev = $site->getEnvironments()->get(self::TARGET_GIT_BRANCH);
            if (!$this->input()->getOption('yes')
                && !$this->io()
                    ->confirm(
                        sprintf(
                            'Multidev environment "%s" already exists. Are you sure you want to delete it and its source branch?',
                            self::TARGET_GIT_BRANCH
                        )
                    )
            ) {
                return;
            }

            $workflow = $multidev->delete(['delete_branch' => true]);
            // @todo: check the result of the workflow.
            $this->processWorkflow($workflow);
        } catch (TerminusNotFoundException $e) {
            if ($git->isRemoteBranchExists(self::TARGET_GIT_BRANCH)) {
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

                $git->executeGitCommand(sprintf('push origin --delete %s', self::TARGET_GIT_BRANCH));
            }
        }

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $git->forcePush(self::TARGET_GIT_BRANCH);

        $this->log()->notice(sprintf('Creating "%s" multidev environment...', self::TARGET_GIT_BRANCH));
        // @todo: create MD env, check if exists - propose deletion.
        // @todo: quietly delete/create MD envs.
        $workflow = $site->getEnvironments()->create(self::TARGET_GIT_BRANCH, $env);
        // @todo: check the result of the workflow.
        $this->processWorkflow($workflow);

        $this->log()->notice('Adding packages to Composer...');
        $composer = new Composer($destinationSitePath);
        $composer->require(self::COMPOSER_DRUPAL_PACKAGE_NAME, self::COMPOSER_DRUPAL_PACKAGE_VERSION);
        $git->commit(
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

        foreach ($projects as $project) {
            // @todo: create a method to add a composer package.
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            $composer->require($packageName, $packageVersion);
            $git->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
            $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
        }
        $this->log()->notice('Packages have been added to Composer');

        $this->log()->notice('Adding comment to pantheon.upstream.yml to trigger a build...');
        // @todo: create a method for updating a file.
        $pantheonUpstreamYml = fopen($destinationSitePath . '/' . 'pantheon.upstream.yml', 'a');
        fwrite($pantheonUpstreamYml, PHP_EOL . '# add a comment to trigger a change and build');
        fclose($pantheonUpstreamYml);
        $git->commit('Trigger Pantheon build');

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', self::TARGET_GIT_BRANCH));
        $git->forcePush(self::TARGET_GIT_BRANCH);

        // @todo: execute "drush cr" on MD env.

        $this->log()->notice('Done!');
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
