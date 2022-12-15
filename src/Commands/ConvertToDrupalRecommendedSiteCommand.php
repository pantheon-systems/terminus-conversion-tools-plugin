<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\DrushCommandsTrait;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitMergeConflictException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitNoDiffException;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException;
use Throwable;

/**
 * Class ConvertToDrupalRecommendedSiteCommand.
 */
class ConvertToDrupalRecommendedSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;
    use DrushCommandsTrait;

    private const TARGET_GIT_BRANCH = 'conversion';
    private const TARGET_UPSTREAM_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-composer-managed.git';
    private const TARGET_UPSTREAM_GIT_BRANCH = 'main';

    private const DRUPAL_PROJECT_UPSTREAM_ID = 'drupal9';
    private const DRUPAL_PROJECT_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-project.git';

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    /**
     * Converts a deprecated Drupal 9 upstream-based site (drupal-project or drupal-recommended) into
     * "drupal-composer-managed" upstream-based one.
     *
     * @command conversion:update-from-deprecated-upstream
     *
     * @option branch The target branch name for multidev env.
     * @option dry-run Skip creating multidev and pushing "drupal-rec" branch.
     * @option target-upstream-git-url The target upstream git repository URL.
     * @option target-upstream-git-branch The target upstream git repository branch.
     * @option run-cr Run `drush cr` after conversion.
     *
     * @param string $site_id
     *   The name or UUID of a site to operate on
     * @param array $options
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function convert(
        string $site_id,
        array $options = [
            'branch' => self::TARGET_GIT_BRANCH,
            'dry-run' => false,
            'target-upstream-git-url' => self::TARGET_UPSTREAM_GIT_REMOTE_URL,
            'target-upstream-git-branch' => self::TARGET_UPSTREAM_GIT_BRANCH,
            'run-cr' => true,
        ]
    ): void {
        $this->setSite($site_id);
        $this->setBranch($options['branch']);

        $upstream_id = $this->site()->getUpstream()->get('machine_name');
        if (self::DRUPAL_PROJECT_UPSTREAM_ID !== $upstream_id
            && self::DRUPAL_RECOMMENDED_UPSTREAM_ID !== $upstream_id) {
            throw new TerminusException(
                'The site {site_name} is not a "drupal-project" or "drupal-recommended" upstream-based site.',
                ['site_name' => $this->site()->getName()]
            );
        }

        $localPath = $this->getLocalSitePath(!$options['continue']);

        $this->setGit($localPath);
        $this->setComposer($localPath);

        $targetGitRemoteName = $this->createLocalGitBranchFromRemote(
            $options['target-upstream-git-url'],
            $options['target-upstream-git-branch']
        );
        if (!$this->areGitReposWithCommonCommits(
            $targetGitRemoteName,
            'origin',
            $options['target-upstream-git-branch'],
            'master'
        )) {
            throw new TerminusException(
                'The site repository and "drupal-composer-managed" upstream repository have unrelated histories.'
            );
        }

        $drupalComposerManagedComposerDependencies = $this->getComposerDependencies($localPath);

        $this->copySiteSpecificFiles();

        $this->log()->notice('Updating composer.json to match "drupal-composer-managed" upstream...');

        $errors = 0;
        $composerManagedComposerJson = json_decode(file_get_contents($localPath . '/composer.json'), true);
        $this->getGit()->checkout(Git::DEFAULT_BRANCH, 'composer.json');
        $this->getGit()->commit('Update composer.json to include site-specific changes', ['composer.json']);
        $this->updateComposerJsonMeta($localPath, $composerManagedComposerJson);
        foreach ($drupalComposerManagedComposerDependencies as $dependency) {
            $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
            if ($dependency['is_dev']) {
                $arguments[] = '--dev';
            }
            try {
                $this->getComposer()->require(...$arguments);
                $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
            } catch (ComposerException $e) {
                $errors++;
                $this->log()->error(
                    sprintf(
                        'Failed updating composer.json: %s',
                        $e->getMessage()
                    )
                );
            }
        }

        if ($errors) {
            throw new ComposerException(
                'Failed updating composer.json. Please check the logs for more information.'
            );
        }

        $this->log()->notice('Updating composer dependencies...');
        $this->getComposer()->update();
        $this->getGit()->commit(
            'Update composer.json to match "drupal-composer-managed" upstream and install dependencies',
            ['composer.json', 'composer.lock']
        );
        $this->log()->notice('composer.json updated to match "drupal-composer-managed" upstream');

        if ($upstream_id === 'drupal9') {
            $this->detectDrupalProjectDiff($localPath);
        } else {
            $this->detectDrupalRecommendedDiff($localPath);
        }

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
            $this->executeDrushCacheRebuild($options);
            $this->log()->notice(sprintf(
                <<<EOD
A multidev environment named "%s" has been created with the upstream changes.
Please test this environment and when you are sure everything works as expected, run:

    {$this->getTerminusExecutable()} conversion:release-to-dev {$this->site()->getName()}

This will push the multidev changes to the dev environment and will switch your site upstream.
EOD,
                $this->getBranch()
            ));
        } else {
            $this->log()->warning('Push to multidev has skipped');
        }
    }

    /**
     * Copies the site-specific files.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function copySiteSpecificFiles(): void
    {
        $this->getGit()->checkout(Git::DEFAULT_BRANCH, '.');

        $siteSpecificFiles = $this->getGit()->diffFileList('--cached', '--diff-filter=A');
        if ($siteSpecificFiles) {
            $this->log()->notice('Copying site-specific files...');
            $this->getGit()->reset();
            $this->getGit()->commit('Copy site-specific files', $siteSpecificFiles);
            $this->log()->notice('Site-specific files have been copied');
        }

        $this->getGit()->reset('HEAD', '--hard');
    }

    /**
     * Updates composer.json metadata.
     *
     * Update the following metadata fields:
     *  - ["installer-paths"]
     *  - ["minimum-stability"]
     *  - ["require"]["pantheon-upstreams/upstream-configuration"]
     *
     * @param string $localPath
     * @param array $composerManagedComposerJson
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function updateComposerJsonMeta(string $localPath, array $composerManagedComposerJson): void
    {
        $composerJson = file_get_contents(Files::buildPath($localPath, 'composer.json'));
        $composerJson = json_decode($composerJson, true);

        // phpcs:disable Generic.Files.LineLength.TooLong
        $composerJson['require']['pantheon-upstreams/upstream-configuration'] = $composerManagedComposerJson['require']['pantheon-upstreams/upstream-configuration'];
        // phpcs:enable Generic.Files.LineLength.TooLong
        $composerJson['require']['drush/drush'] = $composerManagedComposerJson['require']['drush/drush'];

        // Change installer-paths for contrib projects.
        unset($composerJson['extra']['installer-paths']['web/modules/composer/{$name}']);
        unset($composerJson['extra']['installer-paths']['web/profiles/composer/{$name}']);
        unset($composerJson['extra']['installer-paths']['web/themes/composer/{$name}']);
        $composerJson['extra']['installer-paths']['web/modules/contrib/{$name}'] = ['type:drupal-module'];
        $composerJson['extra']['installer-paths']['web/profiles/contrib/{$name}'] = ['type:drupal-profile'];
        $composerJson['extra']['installer-paths']['web/themes/contrib/{$name}'] = ['type:drupal-theme'];

        // Add installer-paths for custom projects.
        $composerJson['extra']['installer-paths']['web/modules/custom/{$name}'] = ['type:drupal-custom-module'];
        $composerJson['extra']['installer-paths']['web/profiles/custom/{$name}'] = ['type:drupal-custom-profile'];
        $composerJson['extra']['installer-paths']['web/themes/custom/{$name}'] = ['type:drupal-custom-theme'];

        foreach (['autoload', 'scripts', 'scripts-descriptions'] as $item) {
            if (!isset($composerManagedComposerJson[$item])) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                throw new TerminusException(
                    sprintf('drupal-composer-managed upstream missing expected portion of composer.json: %s; can not continue.', $item)
                );
                // phpcs:enable Generic.Files.LineLength.TooLong
            }
            $composerJson[$item] = $composerManagedComposerJson[$item];
        }

        foreach (['sort-packages', 'allow-plugins'] as $item) {
            if (!isset($composerManagedComposerJson['config'][$item])) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                throw new TerminusException(
                    sprintf('drupal-composer-managed upstream missing expected portion of composer.json: config.%s; can not continue.', $item)
                );
                // phpcs:enable Generic.Files.LineLength.TooLong
            }
            $composerJson['config'][$item] = $composerManagedComposerJson['config'][$item];
        }

        $file = fopen(Files::buildPath($localPath, 'composer.json'), 'w');
        fwrite($file, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fclose($file);
    }

    /**
     * Returns the list of composer dependencies.
     *
     * @param string $localPath
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getComposerDependencies(string $localPath): array
    {
        $composerJson = file_get_contents(Files::buildPath($localPath, 'composer.json'));
        $composerJson = json_decode($composerJson, true);

        $dependencies = [];
        foreach (['require', 'require-dev'] as $composerJsonSection) {
            $dependencies = array_merge(
                $dependencies,
                array_map(
                    fn($package, $version) => [
                        'package' => $package,
                        'version' => $version,
                        'is_dev' => 'require-dev' === $composerJsonSection,
                    ],
                    array_keys($composerJson[$composerJsonSection]),
                    $composerJson[$composerJsonSection]
                )
            );
        }

        return array_filter(
            $dependencies,
            fn($dependency) => 'self.version' !== $dependency['version']
        );
    }

    /**
     * Detects the differences between the site's code and the given upstream code.
     *
     * If the differences are found, try to apply the patch and ask the user to resolve merge conflicts if found.
     * Otherwise - apply the patch and commit the changes.
     *
     * @param string $localPath
     *   The local path to the site's code.
     * @param string $remoteUrl
     *   The git remote url for the upstream.
     * @param string $upstreamId
     *   The upstream id.
     * @param string $upstreamName
     *   The upstream name.
     */
    private function detectUpstreamDiff(
        string $localPath,
        string $remoteUrl,
        string $upstreamId,
        string $upstreamName
    ): void {
        $this->log()->notice(sprintf(
            <<<EOD
Detecting and applying the differences between the site code and its upstream ("%s")...
EOD,
            $upstreamName
        ));

        try {
            $this->getGit()->addRemote($remoteUrl, $upstreamId);
            $this->getGit()->fetch($upstreamId);

            try {
                $this->getGit()->apply([
                    '--diff-filter=M',
                    sprintf(
                        '%s/%s..%s/%s',
                        $upstreamId,
                        Git::DEFAULT_BRANCH,
                        Git::DEFAULT_REMOTE,
                        Git::DEFAULT_BRANCH
                    ),
                    '--',
                    ':!composer.json',
                ]);
            } catch (GitNoDiffException $e) {
                $this->log()->notice(sprintf(
                    'No differences between the site code and its upstream ("%s") are detected',
                    $upstreamName
                ));

                return;
            } catch (GitMergeConflictException $e) {
                $this->log()->warning(
                    sprintf(
                        // phpcs:disable Generic.Files.LineLength.TooLong
                        <<<EOD
Automatic merge has failed!
The next step in the site conversion process is to resolve the code merge conflicts manually in %s branch:
1. resolve code merge conflicts found in %s files: %s
2. commit the changes - `git add -u && git commit -m 'Copy site-specific code related to "%s" upstream'`
3. run `{$this->getTerminusExecutable()} conversion:push-to-multidev %s` Terminus command to push the code to a multidev env.
EOD,
                        // phpcs:enable Generic.Files.LineLength.TooLong
                        self::TARGET_GIT_BRANCH,
                        $localPath,
                        implode(', ', $e->getUnmergedFiles()),
                        $upstreamName,
                        $this->site()->getName()
                    )
                );

                exit;
            }

            $this->getGit()->commit(sprintf('Copy site-specific code related to "%s" upstream', $upstreamName));
            $this->log()->notice(
                sprintf('The code differences have been copied onto %s branch...', self::TARGET_GIT_BRANCH)
            );
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf(
                    'Failed detecting/applying differences between the site code and its upstream: %s',
                    $t->getMessage()
                )
            );
        }
    }

    /**
     * Detects the differences between the site's code and "drupal-project" upstream code.
     *
     * If the differences are found, try to apply the patch and ask the user to resolve merge conflicts if found.
     * Otherwise - apply the patch and commit the changes.
     *
     * @param string $localPath
     */
    private function detectDrupalProjectDiff(string $localPath): void
    {
        $this->detectUpstreamDiff(
            $localPath,
            self::DRUPAL_PROJECT_GIT_REMOTE_URL,
            self::DRUPAL_PROJECT_UPSTREAM_ID,
            'drupal-project'
        );
    }

    /**
     * Detects the differences between the site's code and "drupal-recommended" upstream code.
     *
     * If the differences are found, try to apply the patch and ask the user to resolve merge conflicts if found.
     * Otherwise - apply the patch and commit the changes.
     *
     * @param string $localPath
     */
    private function detectDrupalRecommendedDiff(string $localPath): void
    {
        $this->detectUpstreamDiff(
            $localPath,
            self::DRUPAL_RECOMMENDED_GIT_REMOTE_URL,
            self::DRUPAL_RECOMMENDED_UPSTREAM_ID,
            'drupal-recommended'
        );
    }
}
