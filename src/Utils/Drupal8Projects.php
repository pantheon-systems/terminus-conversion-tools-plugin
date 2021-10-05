<?php

namespace Pantheon\TerminusConversionTools\Utils;

use League\Container\ContainerAwareTrait;
use Psr\Container\ContainerInterface;

/**
 * Class Drupal8Projects.
 */
class Drupal8Projects
{
    use ContainerAwareTrait;

    /**
     * @var \Pantheon\TerminusConversionTools\Utils\FileSystem
     */
    private $fileSystem;

    /**
     * Drupal8Projects constructor.
     *
     * @param \Psr\Container\ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $container->add(FileSystem::class);
        $this->fileSystem = $container->get(FileSystem::class);
    }

    /**
     * Detects and returns contrib Drupal8 projects.
     *
     * @param string $siteRootPath
     *
     * @return array
     *   The list of contrib Drupal8 projects where the key is a project machine name, the value is a collection of
     *   "*.info.yml" file attributes:
     *     "project" - the project name;
     *     "version" - the version of the project;
     *     "path" - the project's path.
     */
    public function getContribProjects(string $siteRootPath)
    {
        $infoFiles = [];
        foreach ($this->getContribProjectDirectories($siteRootPath) as $projectDir) {
            $infoFiles = array_merge($infoFiles, $this->fileSystem->getFilesByPattern(
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
            $infoFileContent = file_get_contents($infoFilePath . DIRECTORY_SEPARATOR . $infoFileName);
            if (!preg_match('/project: \'(.*)\'/', $infoFileContent, $project)) {
                continue;
            }

            $project = $project[1];

            preg_match('/^(.*)\.info\.yml$/', $infoFileName, $projectMachineName);
            $projectMachineName = $projectMachineName[1];

            preg_match('/version: \'(.*)\'/', $infoFileContent, $version);
            $version = $version[1] ?? null;

            $projects[$projectMachineName] = [
                'project' => $project,
                'version' => $version,
                'path' => $infoFilePath,
            ];
        }

        return $projects;
    }

    /**
     * Returns contrib projects' directories.
     *
     * @param string $siteRootPath
     *
     * @return array
     */
    private function getContribProjectDirectories(string $siteRootPath): array
    {
        return [
            implode(DIRECTORY_SEPARATOR, [$siteRootPath, 'modules']),
            implode(DIRECTORY_SEPARATOR, [$siteRootPath, 'sites', 'all', 'modules']),
            implode(DIRECTORY_SEPARATOR, [$siteRootPath, 'themes']),
            implode(DIRECTORY_SEPARATOR, [$siteRootPath, 'sites', 'all', 'themes']),
        ];
    }
}
