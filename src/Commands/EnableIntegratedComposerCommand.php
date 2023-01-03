<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Yaml\Yaml;

/**
 * Class EnableIntegratedComposerCommand.
 */
class EnableIntegratedComposerCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';

    /**
     * Enable Pantheon Integrated Composer for the site.
     *
     * @command conversion:enable-ic
     *
     * @option branch The target branch name for multidev env.
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on
     * @param array $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function enableIntegratedComposer(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'run-cr' => true,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);
        $localSitePath = $this->getLocalSitePath();
        $this->setGit($localSitePath);

        $this->isValidSite();

        $masterBranch = Git::DEFAULT_BRANCH;
        $this->getGit()->checkout('-b', $this->getBranch(), Git::DEFAULT_REMOTE . '/' . $masterBranch);

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

        $this->executeDrushCacheRebuild($options);

        /** @var \Pantheon\Terminus\Models\Environment $env */
        $env = $this->site()->getEnvironments()->get($this->getBranch());
        $dashboardUrl = $env->dashboardUrl();
        $this->log()->notice(
            <<<EOD
Pantheon Integrated Composer has been enabled for "{$this->getBranch()}" environment ($dashboardUrl).
You can push the changes to "$masterBranch" branch by executing
`{$this->getTerminusExecutable()} multidev:merge-to-dev {$this->site()->getName()}.{$this->getBranch()}` command.
EOD
        );

        $this->log()->notice('Done!');
    }

    /**
     * Returns TRUE if the Site is valid for enabling Pantheon Integrated Composer feature, otherwise throws exception.
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function isValidSite(): bool
    {
        $pantheonYmlContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'pantheon.yml'));
        if (true === ($pantheonYmlContent['build_step'] ?? false)) {
            // The site already uses Pantheon Integrated Composer feature.
            throw new TerminusException(
                'Pantheon Integrated Composer feature is already enabled on the site {site_name}.',
                [
                    'site_name' => $this->site()->getName(),
                ]
            );
        }

        $composerJsonContent = Yaml::parseFile(Files::buildPath($this->getLocalSitePath(), 'composer.json'));
        foreach ($composerJsonContent['require'] as $package => $versionConstraint) {
            if (in_array($package, $this->getIcCompatibleComposerPackages(), true)) {
                return true;
            }
        }
        throw new TerminusException(
            <<<EOD
The site {site_name} is not compatible with Pantheon Integrated Composer feature.
One of the following Composer packages is required: {packages}.
EOD,
            [
                'site_name' => $this->site()->getName(),
                'packages' => implode(', ', $this->getIcCompatibleComposerPackages()),
            ]
        );
    }

    /**
     * Returns the list of Composer packages compatible with Pantheon Integrated Composer feature.
     *
     * @return string[]
     */
    private function getIcCompatibleComposerPackages(): array
    {
        return [
            'drupal/core',
            'drupal/core-recommended',
            'pantheon-systems/wordpress-composer',
        ];
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

        $this->getGit()->commit('Add build_step:true to pantheon.yml', ['pantheon.yml']);
        $this->log()->notice('pantheon.yml config file has been updated.');
    }
}
