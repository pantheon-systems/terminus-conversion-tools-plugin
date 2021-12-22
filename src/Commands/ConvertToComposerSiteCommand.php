<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Drupal8Projects;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const TARGET_UPSTREAM_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';
    private const MODULES_SUBDIR = 'modules';
    private const THEMES_SUBDIR = 'themes';
    private const WEB_ROOT = 'web';

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Drupal8Projects
     */
    private Drupal8Projects $drupal8ComponentsDetector;

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Composer
     */
    private Composer $composer;

    /**
     * @var bool
     */
    private bool $isWebRootSite;

    /**
     * Converts a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @option branch The target branch name for multidev env.
     * @option dry-run Skip creating multidev and pushing composerify branch.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function convert(
        string $site_id,
        array $options = ['branch' => self::TARGET_GIT_BRANCH, 'dry-run' => false]
    ): void {
        $this->site = $this->getSite($site_id);

        if (!$this->site->getFramework()->isDrupal8Framework()) {
            throw new TerminusException(
                'The site {site_name} is not a Drupal 8 based site.',
                ['site_name' => $this->site->getName()]
            );
        }

        if (!in_array(
            $this->site->getUpstream()->get('machine_name'),
            $this->getSupportedSourceUpstreamIds(),
            true
        )) {
            throw new TerminusException(
                'Unsupported upstream {upstream}. Supported upstreams are: {supported_upstreams}.',
                [
                    'upstream' => $this->site->getUpstream()->get('machine_name'),
                    'supported_upstreams' => implode(', ', $this->getSupportedSourceUpstreamIds())
                ]
            );
        }

        $this->branch = $options['branch'];
        $this->validateBranch();

        $defaultConfigFilesDir = Files::buildPath($this->getDrupalAbsolutePath(), 'sites', 'default', 'config');
        $isDefaultConfigFilesExist = is_dir($defaultConfigFilesDir);

        $this->drupal8ComponentsDetector = new Drupal8Projects($this->getDrupalAbsolutePath());
        $this->git = new Git($this->getLocalSitePath());
        $this->composer = new Composer($this->getLocalSitePath());

        $contribProjects = $this->getContribDrupal8Projects();
        $libraryProjects = $this->getLibraries();
        $customProjectsDirs = $this->getCustomProjectsDirectories();

        $this->createLocalGitBranchFromRemote(self::TARGET_UPSTREAM_GIT_REMOTE_URL);
        if ($isDefaultConfigFilesExist) {
            $this->copyConfigurationFiles();
        } else {
            $this->log()->notice(
                sprintf(
                    'Skipped copying configuration files: default configuration files directory (%s) not found.',
                    $defaultConfigFilesDir
                )
            );
        }
        $this->copyPantheonYml();
        $this->copyCustomProjects($customProjectsDirs);
        $this->copySettingsPhp();

        $this->addDrupalComposerPackages($contribProjects);
        $this->addComposerPackages($libraryProjects);

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
            $this->addCommitToTriggerBuild();
        } else {
            $this->log()->warning('Push to multidev has skipped');
        }

        $this->log()->notice('Done!');
    }

    /**
     * Returns TRUE if the site is a webroot-based site.
     *
     * @return bool
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function isWebRootSite(): bool
    {
        if (isset($this->isWebRootSite)) {
            return $this->isWebRootSite;
        }

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'));
        $this->isWebRootSite = $pantheonYmlContent['web_docroot'] ?? false;

        return $this->isWebRootSite;
    }

    /**
     * Returns the absolute site's Drupal core installation directory.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getDrupalAbsolutePath(): string
    {
        return $this->isWebRootSite()
            ? Files::buildPath($this->getLocalSitePath(), self::WEB_ROOT)
            : $this->getLocalSitePath();
    }

    /**
     * Returns webroot-aware relative path.
     *
     * @param string ...$parts
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getWebRootAwareRelativePath(string ...$parts): string
    {
        return $this->isWebRootSite()
            ? Files::buildPath(self::WEB_ROOT, ...$parts)
            : Files::buildPath(...$parts);
    }

    /**
     * Detects and returns the list of Drupal8 libraries.
     *
     * @return array
     *   The list of Composer package names.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getLibraries(): array
    {
        $this->log()->notice(sprintf('Detecting libraries in "%s"...', $this->getDrupalAbsolutePath()));
        $projects = $this->drupal8ComponentsDetector->getLibraries();

        if (0 === count($projects)) {
            $this->log()->notice(sprintf('No libraries were detected in "%s"', $this->getDrupalAbsolutePath()));

            return [];
        }

        $projectsInline = array_map(
            fn($project) => $project,
            $projects
        );
        $this->log()->notice(
            sprintf(
                '%d libraries are detected: %s',
                count($projects),
                implode(', ', $projectsInline)
            )
        );

        return $projects;
    }

    /**
     * Detects and returns the list of contrib Drupal8 projects (modules and themes).
     *
     * @return array
     *   The list of contrib modules and themes where:
     *     "name" is a module/theme name;
     *     "version" is a module/theme version.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getContribDrupal8Projects(): array
    {
        $this->log()->notice(
            sprintf('Detecting contrib modules and themes in "%s"...', $this->getDrupalAbsolutePath())
        );
        $projects = $this->drupal8ComponentsDetector->getContribProjects();
        if (0 === count($projects)) {
            $this->log()->notice(
                sprintf('No contrib modules or themes were detected in "%s"', $this->getDrupalAbsolutePath())
            );

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
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getCustomProjectsDirectories(): array
    {
        $this->log()->notice(
            sprintf('Detecting custom projects (modules and themes) in "%s"...', $this->getDrupalAbsolutePath())
        );
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
     * Copies configuration files.
     */
    private function copyConfigurationFiles(): void
    {
        $this->log()->notice('Copying configuration files...');
        try {
            $sourceRelativePath = $this->getWebRootAwareRelativePath('sites', 'default', 'config');
            $this->git->checkout(Git::DEFAULT_BRANCH, $sourceRelativePath);
            $sourceAbsolutePath = Files::buildPath($this->getDrupalAbsolutePath(), 'sites', 'default', 'config');
            $destinationPath = Files::buildPath($this->getDrupalAbsolutePath(), 'config');
            $this->git->move(sprintf('%s%s*', $sourceAbsolutePath, DIRECTORY_SEPARATOR), $destinationPath);

            $htaccessFile = $this->getWebRootAwareRelativePath('sites', 'default', 'config', '.htaccess');
            $this->git->remove('-f', $htaccessFile);

            if ($this->git->isAnythingToCommit()) {
                $this->git->commit('Pull in configuration from default git branch');
            } else {
                $this->log()->notice('No configuration files found');
            }
        } catch (Throwable $t) {
            $this->log()->warning(sprintf('Failed copying configuration files: %s', $t->getMessage()));
        }
    }

    /**
     * Copies pantheon.yml file and sets the "build_step" flag.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function copyPantheonYml(): void
    {
        $this->log()->notice('Copying pantheon.yml file...');
        $this->git->checkout(Git::DEFAULT_BRANCH, 'pantheon.yml');
        $this->git->commit('Copy pantheon.yml');

        $path = Files::buildPath($this->getLocalSitePath(), 'pantheon.yml');
        $pantheonYmlContent = Yaml::parseFile($path);
        if (isset($pantheonYmlContent['build_step']) && true === $pantheonYmlContent['build_step']) {
            return;
        }

        $pantheonYmlContent['build_step'] = true;
        $pantheonYmlFile = fopen($path, 'wa+');
        fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent, 2, 2));
        fclose($pantheonYmlFile);

        $this->git->commit('Add build_step:true to pantheon.yml');
    }

    /**
     * Adds Drupal contrib project dependencies to composer.json.
     *
     * @param array $contribPackages
     */
    private function addDrupalComposerPackages(array $contribPackages): void
    {
        $this->log()->notice('Adding packages to Composer...');
        try {
            foreach ($this->getDrupal8ComposerDependencies() as $dependency) {
                $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
                if ($dependency['is_dev']) {
                    $arguments[] = '--dev';
                }

                $this->composer->require(...$arguments);
                $this->git->commit(
                    sprintf('Add %s (%s) project to Composer', $dependency['package'], $dependency['version'])
                );
                $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
            }

            $this->composer->install('--no-dev');
            $this->git->commit('Install composer packages');
        } catch (Throwable $t) {
            $this->log()->warning(
                sprintf(
                    'Failed adding and/or installing Drupal 8 dependencies: %s',
                    $t->getMessage()
                )
            );
        }

        foreach ($contribPackages as $project) {
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            try {
                $this->composer->require($packageName, $packageVersion);
                $this->git->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
                $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
            } catch (Throwable $t) {
                $this->log()->warning(
                    sprintf(
                        'Failed adding %s (%s) composer package: %s',
                        $packageName,
                        $packageVersion,
                        $t->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Returns the list of Drupal8 composer dependencies.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getDrupal8ComposerDependencies(): array
    {
        return [
            [
                'package' => 'drupal/core-recommended',
                'version' => '^8.9',
                'is_dev' => false,
            ],
            [
                'package' => 'pantheon-systems/drupal-integrations',
                'version' => '^8',
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-dev',
                'version' => '^8.9',
                'is_dev' => true,
            ],
        ];
    }

    /**
     * Adds dependencies to composer.json.
     *
     * @param array $packages
     */
    private function addComposerPackages(array $packages): void
    {
        $this->log()->notice('Adding packages to Composer...');
        foreach ($packages as $project) {
            try {
                $this->composer->require($project);
                $this->git->commit(sprintf('Add %s project to Composer', $project));
                $this->log()->notice(sprintf('%s is added', $project));
            } catch (Throwable $t) {
                $this->log()->warning(
                    sprintf('Failed adding %s composer package: %s', $project, $t->getMessage())
                );
            }
        }
    }

    /**
     * Copies custom projects (modules and themes).
     *
     * @param array $customProjectsDirs
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
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
                $relativePath = $this->getWebRootAwareRelativePath($relativePath);
                try {
                    $this->git->checkout(Git::DEFAULT_BRANCH, $relativePath);
                    $targetPath = Files::buildPath(self::WEB_ROOT, $subDir, 'custom');

                    if (!is_dir(Files::buildPath($this->getLocalSitePath(), $targetPath))) {
                        mkdir(Files::buildPath($this->getLocalSitePath(), $targetPath), 0755, true);
                    }

                    if (!$this->isWebRootSite()) {
                        $this->git->move(sprintf('%s%s*', $relativePath, DIRECTORY_SEPARATOR), $targetPath);
                    }

                    if ($this->git->isAnythingToCommit()) {
                        $this->git->commit(sprintf('Copy custom %s from %s', $subDir, $relativePath));
                        $this->log()->notice(sprintf('Copied custom %s from %s', $subDir, $relativePath));
                    }
                } catch (Throwable $t) {
                    $this->log()->warning(
                        sprintf('Failed copying custom %s from %s: %s', $subDir, $relativePath, $t->getMessage())
                    );
                }
            }
        }
    }

    /**
     * Copies settings.php file.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function copySettingsPhp(): void
    {
        $this->log()->notice('Copying settings.php file...');
        $settingsPhpFilePath = $this->getWebRootAwareRelativePath('sites', 'default', 'settings.php');
        $this->git->checkout(Git::DEFAULT_BRANCH, $settingsPhpFilePath);

        if (!$this->isWebRootSite()) {
            $this->git->move(
                $settingsPhpFilePath,
                Files::buildPath(self::WEB_ROOT, 'sites', 'default', 'settings.php'),
                '-f',
            );
        }

        if ($this->git->isAnythingToCommit()) {
            $this->git->commit('Copy settings.php');
            $this->log()->notice('settings.php file has been copied.');
        }
    }

    /**
     * Adds a commit to trigger a Pantheon's Integrated Composer build.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function addCommitToTriggerBuild(): void
    {
        $this->log()->notice('Adding comment to pantheon.yml to trigger a build...');
        $path = Files::buildPath($this->getLocalSitePath(), 'pantheon.yml');
        $pantheonYml = fopen($path, 'a');
        fwrite($pantheonYml, PHP_EOL . '# comment to trigger a Pantheon IC build');
        fclose($pantheonYml);

        $this->git->commit('Trigger Pantheon build');
        $this->git->push($this->branch);

        $this->log()->notice('Comment is added');
    }
}
