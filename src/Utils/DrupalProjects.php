<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Symfony\Component\Finder\Finder;

/**
 * Class DrupalProjects.
 */
class DrupalProjects
{
    /**
     * @var string
     */
    private string $siteRootPath;

    /**
     * DrupalProjects constructor.
     */
    public function __construct(string $siteRootPath)
    {
        $this->siteRootPath = $siteRootPath;
    }

    /**
     * Detects and returns the list of contrib Drupal projects.
     *
     * @return array
     *   The list of contrib Drupal projects where the value is the following metadata:
     *     "name" - the project name;
     *     "version" - the version of the project;
     *     "path" - the project's path.
     */
    public function getContribProjects()
    {
        $infoFiles = [];
        $finder = new Finder();
        foreach ($this->getContribProjectDirectories() as $projectDir) {
            $finder->files()->in($projectDir);
            $finder->files()->name('*.info.yml');
            if (!$finder->hasResults()) {
                continue;
            }

            foreach ($finder as $file) {
                $infoFiles[$file->getPath()] = $file->getFilename();
            }
        }

        // Exclude sub-modules and sub-themes.
        ksort($infoFiles);
        $topLevelInfoFiles = array_filter($infoFiles, function ($file, $dir) {
            static $processedDir = null;
            if (null !== $processedDir && false !== strpos($dir, $processedDir)) {
                return false;
            }

            $processedDir = $dir;

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        // Extract "project" and "version" attributes out of info files.
        $projects = [];
        foreach ($topLevelInfoFiles as $infoFilePath => $infoFileName) {
            $infoFileContent = file_get_contents(Files::buildPath($infoFilePath, $infoFileName));
            if (!preg_match('/project: \'(.*)\'/', $infoFileContent, $project)) {
                continue;
            }

            $project = $project[1];

            preg_match('/^(.*)\.info\.yml$/', $infoFileName, $projectMachineName);
            $projectMachineName = $projectMachineName[1];

            if ($project !== $projectMachineName) {
                // Exclude sub-modules.
                continue;
            }

            preg_match('/version: \'(.*)\'/', $infoFileContent, $version);
            $version = isset($version[1]) ? str_replace('8.x-', '', $version[1]) : null;

            $projects[] = [
                'name' => $project,
                'version' => $version,
                'path' => $infoFilePath,
            ];
        }

        return $projects;
    }

    /**
     * Detects and returns the list of Drupal libraries.
     *
     * @return array
     */
    public function getLibraries(): array
    {
        $composerJsonFiles = [];
        $finder = new Finder();
        foreach ($this->getLibrariesDirectories() as $librariesDir) {
            $finder->files()->in($librariesDir . DIRECTORY_SEPARATOR . '*');
            $finder->files()->name('composer.json');
            if (!$finder->hasResults()) {
                continue;
            }

            foreach ($finder as $file) {
                $composerJsonFiles[$file->getPath()] = $file->getFilename();
            }
        }

        $packages = [];
        foreach ($composerJsonFiles as $filePath => $fileName) {
            $composerJsonFileContent = json_decode(
                file_get_contents(Files::buildPath($filePath, $fileName)),
                true
            );

            if (null === $composerJsonFileContent || !isset($composerJsonFileContent['name'])) {
                continue;
            }

            $packages[] = $composerJsonFileContent['name'];
        }

        return $packages;
    }

    /**
     * Returns contrib projects' absolute directories.
     *
     * @return array
     */
    private function getContribProjectDirectories(): array
    {
        return array_filter([
            Files::buildPath($this->siteRootPath, 'modules'),
            Files::buildPath($this->siteRootPath, 'sites', 'all', 'modules'),
            Files::buildPath($this->siteRootPath, 'themes'),
            Files::buildPath($this->siteRootPath, 'sites', 'all', 'themes'),
        ], fn($directory) => is_dir($directory));
    }

    /**
     * Returns custom module relative directories.
     *
     * @return array
     */
    public function getCustomModuleDirectories(): array
    {
        return array_filter([
            Files::buildPath('modules', 'custom'),
            Files::buildPath('sites', 'all', 'modules', 'custom'),
        ], fn($directory) => is_dir(Files::buildPath($this->siteRootPath, $directory))
            && count($this->getCustomProjectSubdirs(Files::buildPath($this->siteRootPath, $directory))) > 0);
    }

    /**
     * Returns libraries absolute directories.
     *
     * @return array
     */
    public function getLibrariesDirectories(): array
    {
        return array_filter([
            Files::buildPath($this->siteRootPath, 'libraries'),
        ], fn($directory) => is_dir($directory));
    }

    /**
     * Returns custom theme relative directories.
     *
     * @return array
     */
    public function getCustomThemeDirectories(): array
    {
        return array_filter([
            Files::buildPath('themes', 'custom'),
            Files::buildPath('sites', 'all', 'themes', 'custom'),
        ], fn($directory) => is_dir(Files::buildPath($this->siteRootPath, $directory))
            && count($this->getCustomProjectSubdirs(Files::buildPath($this->siteRootPath, $directory))) > 0);
    }

    /**
     * Returns the list of subdirectories in custom projects' directory.
     *
     * @param string $directory
     *   The absolute path to custom projects' directory.
     *
     * @return array
     */
    private function getCustomProjectSubdirs(string $directory): array
    {
        return array_filter(
            scandir($directory),
            fn($item) => !in_array(
                $item,
                ['.', '..', '.gitkeep', 'README.txt', 'README.md'],
                true
            )
        );
    }
}
