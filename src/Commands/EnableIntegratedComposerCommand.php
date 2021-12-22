<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class EnableIntegratedComposerCommand.
 */
class EnableIntegratedComposerCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Enable Pantheon Integrated Composer for the site.
     *
     * @command conversion:enable-ic
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function enableIntegratedComposer(string $site_id): void
    {
        $this->site = $this->getSite($site_id);
        $localSitePath = $this->getLocalSitePath(true);
        $this->git = new Git($localSitePath);

        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($localSitePath, 'pantheon.yml'));

        if (true === ($pantheonYmlContent['build_step'] ?? false)) {
            // The site already uses Pantheon Integrated Composer feature.
            throw new TerminusException(
                'Pantheon Integrated Composer feature is already enabled on the site {site_name}.',
                [
                    'site_name' => $this->site->getName(),
                ]
            );
        }

        $this->git->checkout(
            '-b',
            self::TARGET_GIT_BRANCH,
            Git::DEFAULT_REMOTE . '/' . Git::DEFAULT_BRANCH
        );

        $this->log()->notice('Adding paths to .gitignore file...');
        $pathsToIgnore = array_diff($this->getPathsToIgnore(), $this->getGitignorePaths());
        if (count($pathsToIgnore) > 0) {
            $this->addGitignorePaths($pathsToIgnore);
            $this->deletePaths($pathsToIgnore);
        } else {
            $this->log()->notice('No paths to add to .gitignore file.');
        }
    }

    /**
     * Returns the list of paths to ignore by Git.
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getPathsToIgnore(): array
    {
        $composerJsonContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'composer.json'));

        $ignorePaths = array_map(
            function ($path) {
                $path = str_replace('{$name}', '', $path);
                return sprintf('/%s/', trim($path, '/'));
            },
            array_keys($composerJsonContent['extra']['installer-paths'] ?? [])
        );

        array_unshift($ignorePaths, '/vendor/');

        if (isset($composerJsonContent['extra']['drupal-scaffold'])) {
            $ignorePaths[] = '/.editorconfig';
            $ignorePaths[] = '/.gitattributes';
            $ignorePaths[] = '/web/**/.gitignore';

            $drupalScaffoldAllowedPackages = $composerJsonContent['extra']['drupal-scaffold']['allowed-packages'] ?? [];
            if (in_array('pantheon-systems/drupal-integrations', $drupalScaffoldAllowedPackages, true)) {
                $ignorePaths[] = '/.drush-lock-update';
            }
        }

        return $ignorePaths;
    }

    /**
     * Add paths to .gitignore file.
     *
     * @param array $paths
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function addGitignorePaths(array $paths): void
    {
        if (0 === count($paths)) {
            return;
        }

        $f = fopen(Files::buildPath($this->getLocalSitePath(), '.gitignore'), 'a+');
        fwrite($f, '# Ignored paths added by Terminus Conversion Tools Plugin `conversion:enable-ic`' . PHP_EOL);
        fwrite($f, implode(PHP_EOL, $paths));
        fwrite($f, PHP_EOL);
        fclose($f);

        $this->git->commit('Add Composer-generated paths to .gitignore', ['.gitignore']);
        $this->log()->notice(
            sprintf(
                'The following paths have been added to .gitignore file: "%s".',
                implode('", "', $paths)
            )
        );
    }

    /**
     * Returns the list of paths from .gitignore file.
     *
     * @return array
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function getGitignorePaths(): array
    {
        $gitignoreFilePath = Files::buildPath($this->getLocalSitePath(), '.gitignore');
        if (!is_file($gitignoreFilePath)) {
            return [];
        }

        $content = file_get_contents($gitignoreFilePath);

        return array_filter(
            explode(PHP_EOL, $content),
            fn ($path) => !empty($path) && 0 !== strpos(trim($path), '#')
        );
    }

    /**
     * Deletes paths (dirs or files) and commits the outcome.
     *
     * @param array $paths
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function deletePaths(array $paths): void
    {
        $filesystem = new Filesystem();
        foreach ($paths as $pathToDelete) {
            $pathToDelete = trim($pathToDelete, '/\\');
            $absolutePathToDelete = Files::buildPath($this->getLocalSitePath(), $pathToDelete);
            if (!file_exists($absolutePathToDelete)) {
                continue;
            }

            $this->log()->notice(sprintf('Deleting Composer-generated directory "%s"...', $pathToDelete));
            try {
                $filesystem->remove($absolutePathToDelete);
            } catch (IOException $e) {
                $this->log()->warning(sprintf('Failed deleting directory %s.', $pathToDelete));
                continue;
            }

            $this->git->commit(sprintf('Delete Composer-generated path %s', $pathToDelete), [$pathToDelete]);

            $this->log()->notice(sprintf('Directory "%s" has been deleted.', $pathToDelete));
        }
    }
}