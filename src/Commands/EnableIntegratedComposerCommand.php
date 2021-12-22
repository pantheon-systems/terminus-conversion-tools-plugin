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
     * @option branch The target branch name for multidev env.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function enableIntegratedComposer(
        string $site_id,
        array $options = ['branch' => self::TARGET_GIT_BRANCH]
    ): void {
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

        // @todo: consider refactoring ->branch and self::TARGET_GIT_BRANCH.
        $this->branch = $options['branch'];
        $this->git->checkout(
            '-b',
            $this->branch,
            Git::DEFAULT_REMOTE . '/' . Git::DEFAULT_BRANCH
        );

        $this->log()->notice('Adding paths to .gitignore file...');
        $pathsToIgnore = $this->getPathsToIgnore();
        if (count($pathsToIgnore) > 0) {
            $this->addGitignorePaths($pathsToIgnore);
            $this->deletePaths($pathsToIgnore);
        } else {
            $this->log()->notice('No paths detected to add to .gitignore file.');
        }

        $this->updatePantheonYmlConfig();
        $this->pushTargetBranch();
        $this->addCommitToTriggerBuild();

        $dashboardUrl = $this->site->getEnvironments()->get($this->branch)->dashboardUrl();
        if ($this->input()->getOption('yes') || 'push' === $this->io()->choice(
            <<<EOD
Pantheon Integrated Composer has been enabled for "$this->branch" environment ($dashboardUrl).
You can push the changes to "master" branch immediately or do it later by executing
`terminus multidev:merge-to-dev {$this->site->getName()}.$this->branch` command.
EOD,
            ['cancel' => 'Cancel', 'push' => 'Push to master'],
            'cancel'
        )) {
            $this->log()->notice('Pushing changes to "master" branch...');
            $this->git->checkout(Git::DEFAULT_BRANCH);
            $this->git->merge($this->branch);
            $this->git->push(Git::DEFAULT_BRANCH);
            $this->log()->notice('Pantheon Integrated Composer has been enabled for "master".');
        }

        $this->log()->notice('Done!');
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

        return array_diff($ignorePaths, $this->getGitignorePaths());
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

        $gitignoreFile = fopen(Files::buildPath($this->getLocalSitePath(), '.gitignore'), 'a+');
        if (false === $gitignoreFile) {
            throw new TerminusException('Failed to open .gitignore file for writing');
        }
        fwrite(
            $gitignoreFile,
            '# Ignored paths added by Terminus Conversion Tools Plugin `conversion:enable-ic`' . PHP_EOL
        );
        fwrite($gitignoreFile, implode(PHP_EOL, $paths));
        fwrite($gitignoreFile, PHP_EOL);
        fclose($gitignoreFile);

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
     * Deletes paths (directories or files) and commits the outcome.
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

            $this->git->commit(sprintf('Delete Composer-generated path "%s"', $pathToDelete), [$pathToDelete]);

            $this->log()->notice(sprintf('Directory "%s" has been deleted.', $pathToDelete));
        }
    }

    /**
     * Sets "build_step" config value to TRUE in pantheon.yml file.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function updatePantheonYmlConfig(): void
    {
        $this->log()->notice('Setting "build_step" to "true" in pantheon.yml config file...');
        $path = Files::buildPath($this->getLocalSitePath(), 'pantheon.yml');

        $pantheonYmlContent = Yaml::parseFile($path);
        $pantheonYmlContent['build_step'] = true;

        $pantheonYmlFile = fopen($path, 'wa+');
        if (false === $pantheonYmlFile) {
            throw new TerminusException('Failed to open pantheon.yml file for writing');
        }
        fwrite($pantheonYmlFile, Yaml::dump($pantheonYmlContent, 2, 2));
        fclose($pantheonYmlFile);

        $this->git->commit('Add build_step:true to pantheon.yml', ['pantheon.yml']);
        $this->log()->notice('pantheon.yml config file has been updated.');
    }
}
