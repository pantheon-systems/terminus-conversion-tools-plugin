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

    private const TARGET_GIT_BRANCH = 'drupal10';

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
        ]
    ): void {
        $options['run-updb'] = true;
        $options['run-cr'] = true;

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

        $this->log()->notice('There will be a configuration export as part of this process, you should import configuration once merged to the dev environment.');

        $masterBranch = Git::DEFAULT_BRANCH;
        $this->getGit()->checkout('-b', $this->getBranch(), Git::DEFAULT_REMOTE . '/' . $masterBranch);

        // Phpstan was included in Drupal 10, so it needs to be allowed.
        $this->getComposer()->config('--no-plugins', 'allow-plugins.phpstan/extension-installer', 'true');
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit(
                'Add phpstan/extension-installer to allow-plugins'
            );
        }
        $this->pushTargetBranch();

        $this->enableNewModules();
        $editorsToConvert = $this->getEditorsToConvert();

        $required = $this->requireRemovedProjects();

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

        $this->getGit()->push($this->getBranch());
        $this->executeDrushDatabaseUpdates($options);
        $this->executeDrushCacheRebuild($options);

        $env = $this->site()->getEnvironments()->get($this->getBranch());
        $workflow = $env->changeConnectionMode('sftp');
        $this->processWorkflow($workflow);
        $command = 'config:export -y';
        $result = $this->runDrushCommand($command, $this->getBranch());
        if (0 !== $result['exit_code']) {
            throw new TerminusException(
                'Fail to export configuration. Error: {error}',
                [
                    'error' => $result['stderr'],
                ]
            );
        }

        $this->processWorkflow($env->commitChanges('Export configuration changes after Drupal 10 upgrade.'));
        $this->log()->notice('Your code was committed.');

        foreach ($required['themes'] as $theme) {
            $themeRecommendation = $this->getThemeRecommendation($theme);
            $this->log()->warning($themeRecommendation);
        }

        foreach ($editorsToConvert as $editor) {
            $this->log()->notice(sprintf('If you wish to convert the editor %s to ckeditor5, you should visit your site /admin/config/content/formats/manage/%s and make the change. See https://www.drupal.org/node/3308362 for reference.', $editor, $editor));
        }
        if ($editorsToConvert) {
            $this->log()->notice('Remember to export configuration if you make any editor change.');
        }

        $this->log()->notice(sprintf('Site %s has been upgraded to Drupal 10', $this->site()->getName()));

        $this->log()->notice('Done!');
    }

    /**
     * Get theme recommendation.
     */
    protected function getThemeRecommendation(string $theme): string
    {
        $newThemes = [
            'bartik' => 'olivero',
            'seven' => 'claro',
            'classy' => '',
            'stable' => '',
        ];
        if (isset($newThemes[$theme])) {
            $newTheme = $newThemes[$theme];
            if ($newTheme) {
                return sprintf('Theme %s was removed from Drupal 10 and re-added to your site via contrib project. Please consider using %s theme instead.', $theme, $newTheme);
            }
            return sprintf('Theme %s was removed from Drupal 10 and re-added to your site via contrib project. It is suggested to use the new themes starterkits instead.', $theme);
        }
    }

    /**
     * Enable new mysql module while still on Drupal 9.
     */
    protected function enableNewModules(): void
    {
        $command = 'en -y mysql ckeditor5';
        $result = $this->runDrushCommand($command, $this->getBranch());

        if (0 !== $result['exit_code']) {
            throw new TerminusException(
                'Enable mysql command not found or not successful. Error: {error}',
                [
                    'error' => $result['stderr'],
                ]
            );
        }
    }

    /**
     * Convert text formats to use Ckeditor 5.
     */
    protected function getEditorsToConvert(): array
    {
        $editorsToConvert = [];
        $command = "sqlq \"SELECT distinct(name) FROM config WHERE name LIKE 'editor.editor.%'\"";
        $result = $this->runDrushCommand($command, $this->getBranch());
        if (0 !== $result['exit_code']) {
            throw new TerminusException(
                'Error querying text formats to convert them to ckeditor5. Error: {error}',
                [
                    'error' => $result['stderr'],
                ]
            );
        }
        $editors = explode("\n", trim($result['output']));
        foreach ($editors as $editor) {
            $command = sprintf("config:get %s editor", $editor);
            $result = $this->runDrushCommand($command, $this->getBranch());
            if (0 !== $result['exit_code']) {
                throw new TerminusException(
                    'Error getting text editor {editor}. Error: {error}',
                    [
                        'editor' => $editor,
                        'error' => $result['stderr'],
                    ]
                );
            }
            $editorPluginOutput = trim($result['output']);
            if (preg_match("/'[a-z\.\:\_]+'\:\s(.*)/", $editorPluginOutput, $matches)) {
                $editorPlugin = $matches[1] ?? '';
                if ($editorPlugin === 'ckeditor') {
                    preg_match('/editor\.editor\.([a-z_]+)/', $editor, $matches);
                    $editorsToConvert[] = $matches[1];
                }
            }
        }
        return $editorsToConvert;
    }

    /**
     * Require projects that will be removed from Drupal 10.
     */
    protected function requireRemovedProjects(): array
    {
        $candidates = [
            'modules' => [
                'aggregator',
                'ckeditor',
                'color',
                'hal',
                'quick_edit',
                'rdf',
            ],
            'themes' => [
                'classy',
                'stable',
                'bartik',
                'seven',
            ],
        ];
        $required = [
            'modules' => [],
            'themes' => [],
        ];

        $command = 'pm:list --type=module,theme --status=enabled --core --field=name';
        $result = $this->runDrushCommand($command, $this->getBranch());
        if (0 !== $result['exit_code']) {
            throw new TerminusException(
                'Error getting enabled modules and themes. Error: {error}',
                [
                    'error' => $result['stderr'],
                ]
            );
        }
        $enabled = explode("\n", trim($result['output']));

        foreach ($candidates as $type => $projects) {
            foreach ($projects as $project) {
                if (in_array($project, $enabled)) {
                    $this->getComposer()->require(sprintf("drupal/%s", $project), null, '--no-update');
                    $required[$type][] = $project;
                }
            }
        }
        $this->getComposer()->update();
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Require modules and themes to be removed in Drupal 10.');
            $this->log()->notice('Composer update has been executed.');
        }
        return $required;
    }
}
