<?php

namespace Pantheon\TerminusConversionTools\Utils;

/**
 * Class Drupal8Projects.
 */
class Drupal8Projects
{
    /**
     * @var string
     */
    private string $siteRootPath;

    /**
     * Drupal8Projects constructor.
     */
    public function __construct(string $siteRootPath)
    {
        $this->siteRootPath = $siteRootPath;
    }

    /**
     * Detects and returns the list of contrib Drupal8 projects.
     *
     * @return array
     *   The list of contrib Drupal8 projects where the value is the following metadata:
     *     "name" - the project name;
     *     "version" - the version of the project;
     *     "path" - the project's path.
     */
    public function getContribProjects()
    {
        $infoFiles = [];
        foreach ($this->getContribProjectDirectories() as $projectDir) {
            $infoFiles = array_merge($infoFiles, FileSystem::getFilesByPattern(
                $projectDir,
                '/\.info\.yml$/'
            ));
        }

        // Exclude sub-modules and sub-themes.
        ksort($infoFiles);
        $processedDir = '';
        $topLevelInfoFiles = array_filter($infoFiles, function ($file, $dir) use (&$processedDir) {
            if (false !== strpos($dir, $processedDir)) {
                return false;
            }

            $processedDir = $dir;

            return true;
        }, ARRAY_FILTER_USE_BOTH);

        // Extract "project" and "version" attributes out of info files.
        $projects = [];
        foreach ($topLevelInfoFiles as $infoFilePath => $infoFileName) {
            $infoFileContent = file_get_contents(FileSystem::buildPath($infoFilePath, $infoFileName));
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
     * Returns contrib projects' directories.
     *
     * @return array
     */
    private function getContribProjectDirectories(): array
    {
        return [
            FileSystem::buildPath($this->siteRootPath, 'modules'),
            FileSystem::buildPath($this->siteRootPath, 'sites', 'all', 'modules'),
            FileSystem::buildPath($this->siteRootPath, 'themes'),
            FileSystem::buildPath($this->siteRootPath, 'sites', 'all', 'themes'),
        ];
    }

    /**
     * Returns custom module directories.
     *
     * @return array
     */
    public function getCustomModuleDirectories(): array
    {
        return array_filter([
            FileSystem::buildPath($this->siteRootPath, 'modules', 'custom'),
            FileSystem::buildPath($this->siteRootPath, 'sites', 'all', 'modules', 'custom'),
        ], fn($directory) => is_dir($directory));
    }

    /**
     * Returns custom theme directories.
     *
     * @return array
     */
    public function getCustomThemeDirectories(): array
    {
        return array_filter([
            FileSystem::buildPath($this->siteRootPath, 'themes', 'custom'),
            FileSystem::buildPath($this->siteRootPath, 'sites', 'all', 'themes', 'custom'),
        ], fn($directory) => is_dir($directory));
    }
}
