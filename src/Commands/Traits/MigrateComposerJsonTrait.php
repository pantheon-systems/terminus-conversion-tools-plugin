<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Pantheon\TerminusConversionTools\Utils\Files;
use Throwable;
use Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException;

/**
 * Trait MigrateComposerJsonTrait.
 */
trait MigrateComposerJsonTrait
{
    use ComposerAwareTrait;
    use GitAwareTrait;

    /**
     * @var array
     */
    private array $sourceComposerJson;

    /**
     * @var string
     */
    private string $projectPath;

    /**
     * Migrates composer.json components.
     *
     * @param array $sourceComposerJson
     *   Content of the source composer.json file.
     * @param string $projectPath
     *   Path to Composer project.
     * @param array $contribProjects
     *   Drupal contrib dependencies.
     * @param array $libraryProjects
     *   Drupal library dependencies.
     * @param string|null $librariesBackupPath
     *   Path to backup of libraries.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function migrateComposerJson(
        array  $sourceComposerJson,
        string $projectPath,
        array  $contribProjects = [],
        array  $libraryProjects = [],
        string $librariesBackupPath = null
    ): void {
        $this->log()->notice('Migrating Composer project components...');

        $this->sourceComposerJson = $sourceComposerJson;
        $this->projectPath = $projectPath;
        $this->setComposer($projectPath);

        $this->copyMinimumStability();
        $this->copyComposerRepositories();
        $this->copyAllowPluginsConfiguration();
        $this->addDrupalComposerPackages($contribProjects);
        $this->addComposerPackages($libraryProjects);

        $missingPackages = $this->getMissingComposerPackages($this->getComposer()->getComposerJsonData());
        $this->addComposerPackages($missingPackages);
        $this->log()->notice(
            // phpcs:disable Generic.Files.LineLength.TooLong
            <<<EOD
Composer repositories, require and require-dev sections have been migrated. Look at the logs for any errors in the process.
EOD
            // phpcs:enable Generic.Files.LineLength.TooLong
        );
        $this->log()->notice(
            <<<EOD
Please note that other composer.json sections: config, extra, etc. should be manually migrated if needed.
EOD
        );

        $this->copyComposerPackagesConfiguration();

        if ($librariesBackupPath && is_dir($librariesBackupPath)) {
            $this->restoreLibraries($librariesBackupPath);
        }
    }

    /**
     * Copy extra composer repositories if they exist.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyComposerRepositories(): void
    {
        $sourceRepositories = $this->sourceComposerJson['repositories'] ?? [];
        if (!$sourceRepositories) {
            return;
        }

        $composerJson = $this->getComposer()->getComposerJsonData();
        $repositories = $composerJson['repositories'] ?? [];

        $diff = array_filter(
            $sourceRepositories,
            fn($sourceRepository) => !array_filter(
                $repositories,
                fn($repository) => $repository == $sourceRepository
            )
        );
        if (!$diff) {
            return;
        }

        $composerJson['repositories'] = array_values(array_merge($repositories, $diff));
        $this->getComposer()->writeComposerJsonData($composerJson);
    }

    /**
     * Restore libraries from the backup path and commit them.
     *
     * @param string $librariesBackupPath
     *   Path to backup of libraries.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function restoreLibraries(string $librariesBackupPath): void
    {
        $finder = new Finder();
        $filesystem = new Filesystem();
        $gitignoreContent = file_get_contents(Files::buildPath($this->localSitePath, '.gitignore'));
        $gitignoreContentUpdated = str_replace('/web/libraries/', '/web/libraries/*', $gitignoreContent);
        if ($gitignoreContent !== $gitignoreContentUpdated) {
            file_put_contents(Files::buildPath($this->localSitePath, '.gitignore'), $gitignoreContentUpdated);
            $this->getGit()->commit('Fix libraries in .gitignore', ['.gitignore']);
        }
        foreach ($finder->directories()->in($librariesBackupPath)->depth(0) as $folder) {
            $filesystem->mirror(
                $folder->getPathname(),
                Files::buildPath($this->localSitePath, '/web/libraries/', $folder->getRelativePathname())
            );
            $libraryPath = Files::buildPath('web/libraries', $folder->getRelativePathname());
            if ($this->getGit()->isIgnoredPath($libraryPath)) {
                $this->getGit()->appendToIgnore(sprintf('!%s', $libraryPath));
            }
            $this->getGit()->commit(sprintf('Copy library %s', $folder->getRelativePathname()), [$libraryPath]);
        }
        $filesystem->remove($librariesBackupPath);
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Remove libraries backup folder.');
        }
    }

    /**
     * Adds Drupal contrib project dependencies to composer.json.
     *
     * @param array $contribPackages
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function addDrupalComposerPackages(array $contribPackages): void
    {
        $errors = 0;
        try {
            foreach ($this->getDrupalComposerDependencies() as $dependency) {
                $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
                if ($dependency['is_dev']) {
                    $arguments[] = '--dev';
                }

                $this->getComposer()->require(...$arguments);
                if ($dependency['package'] === 'drupal/core') {
                    // We should remove drupal/core-recommended because it's a conflict,
                    // and it's required in the upstream.
                    $this->getComposer()->remove('drupal/core-recommended', '--no-update');
                }
                if ($this->getGit()->isAnythingToCommit()) {
                    $this->getGit()->commit(
                        sprintf('Add %s (%s) project to Composer', $dependency['package'], $dependency['version'])
                    );
                    $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
                }
            }

            $this->getComposer()->update('--no-dev');
            if ($this->getGit()->isAnythingToCommit()) {
                $this->getGit()->commit('Install composer packages');
            }
        } catch (Throwable $t) {
            $errors++;
            $this->log()->warning(
                sprintf(
                    'Failed adding and/or installing Drupal 8 dependencies: %s',
                    $t->getMessage()
                )
            );
        }
        if ($errors) {
            throw new ComposerException(
                'There have been errors adding composer packages, please review previous messages.'
            );
        }

        $errors = 0;
        foreach ($contribPackages as $project) {
            $packageName = sprintf('drupal/%s', $project['name']);
            $packageVersion = sprintf('^%s', $project['version']);
            try {
                $this->getComposer()->require($packageName, $packageVersion);
                $this->getGit()->commit(sprintf('Add %s (%s) project to Composer', $packageName, $packageVersion));
                $this->log()->notice(sprintf('%s (%s) is added', $packageName, $packageVersion));
            } catch (Throwable $t) {
                $errors++;
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
        if ($errors) {
            throw new ComposerException(
                'There have been errors adding composer packages, please review previous messages.'
            );
        }
    }

    /**
     * Returns whether the sources composer json has drupal/core-recommended or not.
     *
     * @return bool
     */
    private function sourceHasDrupalCoreRecommended(): bool
    {
        return isset($this->sourceComposerJson['require']['drupal/core-recommended']);
    }

    /**
     * Returns the list of Drupal composer dependencies.
     *
     * @param string|null $forceDrupalVersion
     *   Force Drupal version to the once received.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getDrupalComposerDependencies(string $forceDrupalVersion = null): array
    {

        $drupalConstraint = $forceDrupalVersion
            ?? $this->sourceComposerJson['require']['drupal/core-recommended']
            ?? $this->sourceComposerJson['require']['drupal/core']
            ?? '^8.9';

        $drupalPackage = $this->sourceHasDrupalCoreRecommended() ? 'drupal/core-recommended' : 'drupal/core';

        preg_match('/^\^?([0-9]{1,2}).*/', $drupalConstraint, $matches);
        $drupalIntegrationsConstraint = "^" . $matches[1] ?? '^8|^9|^10';

        if ($drupalIntegrationsConstraint === "^10") {
            $this->getComposer()->config('platform.php', '8.1');
        }

        $packages = [
            [
                'package' => $drupalPackage,
                'version' => $drupalConstraint,
                'is_dev' => false,
            ],
            [
                'package' => 'pantheon-systems/drupal-integrations',
                'version' => $drupalIntegrationsConstraint,
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-composer-scaffold',
                'version' => $drupalConstraint,
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-dev',
                'version' => $drupalConstraint,
                'is_dev' => true,
            ],
        ];

        $packages[] = [
            'package' => 'drush/drush',
            'version' => '^10|^11',
            'is_dev' => false,
        ];

        return $packages;
    }

    /**
     * Returns the list of missing packages after comparing original and current composer json files.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getMissingComposerPackages(array $currentComposerJson): array
    {
        $missingPackages = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach ($this->sourceComposerJson[$section] ?? [] as $package => $version) {
                if (isset($currentComposerJson[$section][$package])) {
                    continue;
                }
                $missingPackages[] = ['package' => $package, 'version' => $version, 'is_dev' => 'require' !== $section];
            }
        }
        return $missingPackages;
    }


    /**
     * Adds dependencies to composer.json.
     *
     * @param array $packages The list of packages to add.
     *   It could be just the name or an array with the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function addComposerPackages(array $packages): void
    {
        $errors = 0;
        foreach ($packages as $project) {
            if (is_string($project)) {
                $project = [
                    'package' => $project,
                    'version' => null,
                    'is_dev' => false,
                ];
            }
            $package = $project['package'];
            // Workaround build tools D8 issue with specific package.
            if ($package === 'symfony/css-selector') {
                $project['version'] = '^3.4';
            }
            $arguments = [$package, $project['version']];
            $options = $project['is_dev'] ? ['--dev'] : [];
            $options[] = '-n';
            $options[] = '-W';
            try {
                $this->getComposer()->require(...$arguments, ...$options);
                $this->getGit()->commit(sprintf('Add %s project to Composer', $package));
                $this->log()->notice(sprintf('%s is added', $package));
            } catch (Throwable $t) {
                $errors++;
                $this->log()->warning(
                    sprintf('Failed adding %s composer package: %s', $package, $t->getMessage())
                );
            }
        }
        if ($errors) {
            throw new ComposerException(
                'There have been errors adding composer packages, please review previous messages.'
            );
        }
    }

    /**
     * Copy composer well-known packages configuration.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function copyComposerPackagesConfiguration(): void
    {
        $this->copyComposerPatchesConfiguration();
        $this->copyComposerInstallersExtenderConfiguration();
        $this->copyExtraComposerInstallersConfiguration();
        if ($this->getGit()->isAnythingToCommit()) {
            $this->getGit()->commit('Copy extra composer configuration.');
        } else {
            $this->log()->notice('No extra composer configuration found.');
        }
    }

    /**
     * Copy cweagans/composer-patches configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyComposerPatchesConfiguration(): void
    {
        $packageName = 'cweagans/composer-patches';
        $extraKeys = [
            'patches',
            'patches-file',
            'enable-patching',
            'patches-ignore',
            'composer-exit-on-patch-failure',
        ];
        if (!isset($this->sourceComposerJson['require'][$packageName])
            && !isset($this->sourceComposerJson['require-dev'][$packageName])
        ) {
            return;
        }
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        foreach ($extraKeys as $key) {
            if (isset($this->sourceComposerJson['extra'][$key])) {
                $currentComposerJson['extra'][$key] = $this->sourceComposerJson['extra'][$key];
                if ($key === 'patches-file') {
                    $this->log()->warning(
                        <<<EOD
cweagans/composer-patches patches-file option was copied, but you should manually copy the patches file.
EOD
                    );
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy oomphinc/composer-installers-extender configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyComposerInstallersExtenderConfiguration(): void
    {
        $packageName = 'oomphinc/composer-installers-extender';
        if (!isset($this->sourceComposerJson['require'][$packageName])
            && !isset($this->sourceComposerJson['require-dev'][$packageName])
        ) {
            return;
        }
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        if (isset($this->sourceComposerJson['extra']['installer-types'])) {
            $installerTypes = $this->sourceComposerJson['extra']['installer-types'];
            $currentComposerJson['extra']['installer-types'] =
                $this->sourceComposerJson['extra']['installer-types'];
            foreach ($this->sourceComposerJson['extra']['installer-paths'] ?? [] as $path => $types) {
                if (array_intersect($installerTypes, $types)) {
                    $currentComposerJson['extra']['installer-paths'][$path] = $types;
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy extra composer/installer configuration if exists.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyExtraComposerInstallersConfiguration(): void
    {
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        $currentTypes = [];

        $installerPaths = [];
        if (isset($currentComposerJson['extra']['installer-paths'])) {
            $installerPaths = &$currentComposerJson['extra']['installer-paths'];
        }
        foreach ($installerPaths as $path => $types) {
            $currentTypes += $types;
        }

        foreach ($this->sourceComposerJson['extra']['installer-paths'] ?? [] as $path => $types) {
            if (!isset($installerPaths[$path])) {
                foreach ($types as $type) {
                    if (in_array($type, $currentTypes)) {
                        continue;
                    }
                    $installerPaths[$path][] = $type;
                }
            } else {
                if ($installerPaths[$path] !== $types) {
                    $installerPaths[$path] = array_values(array_unique(array_merge($installerPaths[$path], $types)));
                }
            }
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copy minimum stability setting.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyMinimumStability(): void
    {
        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        if (isset($this->sourceComposerJson['minimum-stability'])) {
            $currentComposerJson['minimum-stability'] = $this->sourceComposerJson['minimum-stability'];
        }
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }

    /**
     * Copies "allow-plugins" config.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function copyAllowPluginsConfiguration(): void
    {
        if (!isset($this->sourceComposerJson['config']['allow-plugins'])) {
            return;
        }

        $currentComposerJson = $this->getComposer()->getComposerJsonData();
        $currentComposerJson['config']['allow-plugins'] = array_merge(
            $currentComposerJson['config']['allow-plugins'] ?? [],
            $this->sourceComposerJson['config']['allow-plugins']
        );
        $this->getComposer()->writeComposerJsonData($currentComposerJson);
    }
}
