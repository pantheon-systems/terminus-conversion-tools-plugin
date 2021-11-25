<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException;
use Pantheon\TerminusConversionTools\Utils\Composer;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Throwable;

/**
 * Class ConvertToDrupalRecommendedSiteCommand.
 */
class ConvertToDrupalRecommendedSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    private const TARGET_GIT_BRANCH = 'drupal-rec';
    private const TARGET_UPSTREAM_GIT_REMOTE_NAME = 'target-upstream';
    private const TARGET_UPSTREAM_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-recommended.git';

    private const DRUPAL_PROJECT_UPSTREAM_ID = 'drupal9';
    private const DRUPAL_PROJECT_GIT_REMOTE_URL = 'https://github.com/pantheon-upstreams/drupal-project.git';

    /**
     * @var string
     */
    private string $localPath;

    /**
     * @var string
     */
    private string $branch;

    /**
     * Converts a "drupal-project" upstream-based site into "drupal-recommended" upstream-based one.
     *
     * @command conversion:drupal-recommended
     *
     * @option branch The target branch name for multidev env.
     * @option dry-run Skip creating multidev and pushing "drupal-rec" branch.
     * @option continue Continue an interrupted conversion process caused by code merge conflicts.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function convert(
        string $site_id,
        array $options = ['branch' => self::TARGET_GIT_BRANCH, 'dry-run' => false, 'continue' => false]
    ): void {
        $this->site = $this->getSite($site_id);

        $upstream_id = $this->site->getUpstream()->get('machine_name');
        if (self::DRUPAL_PROJECT_UPSTREAM_ID !== $upstream_id) {
            throw new TerminusException(
                'The site {site_name} is not a "drupal-project" upstream-based site.',
                ['site_name' => $this->site->getName()]
            );
        }

        $this->branch = $options['branch'];
        if (strlen($this->branch) > 11) {
            throw new TerminusException(
                'The target git branch name for multidev env must not exceed 11 characters limit'
            );
        }

        $this->localPath = $this->cloneSiteGitRepository(!$options['continue']);

        $this->git = new Git($this->localPath);
        $composer = new Composer($this->localPath);

        if ($options['continue']) {
            if (!$options['dry-run']) {
                $this->pushTargetBranch();
            }

            return;
        }

        $this->createLocalGitBranch();
        $drupalRecommendedComposerDependencies = $this->getComposerDependencies();

        $this->copySiteSpecificFiles();

        $this->log()->notice('Updating composer.json to match "drupal-recommended" upstream...');
        try {
            $this->git->checkout(Git::DEFAULT_BRANCH, 'composer.json');
            $this->git->commit('Update composer.json to include site-specific changes', ['composer.json']);

            $this->updateComposerJsonMeta();
            foreach ($drupalRecommendedComposerDependencies as $dependency) {
                $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
                if ($dependency['is_dev']) {
                    $arguments[] = '--dev';
                }

                $composer->require(...$arguments);
                $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
            }

            $this->log()->notice('Updating composer dependencies...');
            $composer->update();
            $this->git->commit(
                'Update composer.json to match "drupal-recommended" upstream and install dependencies',
                ['composer.json', 'composer.lock']
            );
            $this->log()->notice('composer.json updated to match "drupal-recommended" upstream');
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf(
                    'Failed updating composer.json: %s',
                    $t->getMessage()
                )
            );
        }

        $this->detectDrupalProjectDiff();

        if (!$options['dry-run']) {
            $this->pushTargetBranch();
        }
    }

    /**
     * Pushes the target branch to the site repository.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function pushTargetBranch(): void
    {
        try {
            $this->deleteMultidevIfExists($this->branch);
        } catch (TerminusCancelOperationException $e) {
            return;
        }

        $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', $this->branch));
        $this->git->push($this->branch);
        $mdEnv = $this->createMultidev($this->branch);

        $this->log()->notice(
            sprintf('Link to "%s" multidev environment dashboard: %s', $this->branch, $mdEnv->dashboardUrl())
        );
    }

    /**
     * Copies the site-specific files.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function copySiteSpecificFiles(): void
    {
        $this->git->checkout(Git::DEFAULT_BRANCH, '.');

        $siteSpecificFiles = $this->git->diffFileList('--cached', '--diff-filter=A');
        if ($siteSpecificFiles) {
            $this->log()->notice('Copying site-specific files...');
            $this->git->reset();
            $this->git->commit('Copy site-specific files', $siteSpecificFiles);
            $this->log()->notice('Site-specific files have been copied');
        }

        $this->git->reset('HEAD', '--hard');
    }

    /**
     * Updates composer.json metadata.
     *
     * Update the following metadata fields:
     *  - ["installer-paths"]
     *  - ["minimum-stability"]
     *  - ["require"]["pantheon-upstreams/upstream-configuration"]
     */
    private function updateComposerJsonMeta(): void
    {
        $composerJson = file_get_contents(Files::buildPath($this->localPath, 'composer.json'));
        $composerJson = json_decode($composerJson, true);

        $composerJson['require']['pantheon-upstreams/upstream-configuration'] = 'self.version';
        $composerJson['minimum-stability'] = 'stable';

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

        $file = fopen(Files::buildPath($this->localPath, 'composer.json'), 'w');
        fwrite($file, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fclose($file);
    }

    /**
     * Returns the list of composer dependencies.
     *
     * @return array[]
     *   Each dependency is an array that consists of the following keys:
     *     "package" - a package name;
     *     "version" - a version constraint;
     *     "is_dev" - a "dev" package flag.
     */
    private function getComposerDependencies(): array
    {
        $composerJson = file_get_contents(Files::buildPath($this->localPath, 'composer.json'));
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
     * Detects the differences between the site's code and "drupal-project" upstream code.
     *
     * If the differences are found, try to apply the patch and ask the user to resolve merge conflicts if found.
     * Otherwise - apply the patch and commit the changes.
     */
    private function detectDrupalProjectDiff(): void
    {
        $this->log()->notice(
            <<<EOD
Detecting the differences between the site code and its upstream ("drupal-project") that need to be applied...
EOD
        );

        try {
            $this->git->addRemote(self::DRUPAL_PROJECT_GIT_REMOTE_URL, self::DRUPAL_PROJECT_UPSTREAM_ID);
            $this->git->fetch(self::DRUPAL_PROJECT_UPSTREAM_ID);

            $diffOptions = [
                '--diff-filter=M',
                sprintf(
                    '%s/%s..%s/%s',
                    self::DRUPAL_PROJECT_UPSTREAM_ID,
                    Git::DEFAULT_BRANCH,
                    Git::DEFAULT_REMOTE,
                    Git::DEFAULT_BRANCH
                ),
                '--',
                ':!composer.json',
            ];
            $diffFilesList = $this->git->diffFileList(...$diffOptions);
            if (!$diffFilesList) {
                $this->log()->notice(
                    'No differences between the site code and its upstream ("drupal-project") are detected'
                );

                return;
            }

            $this->log()->warning(
                sprintf(
                    'The code differences detected in the following files: %s',
                    implode(', ', $diffFilesList)
                )
            );

            $this->log()->notice(sprintf('Copying the code differences onto %s branch...', self::TARGET_GIT_BRANCH));
            $patch = $this->git->diff(...$diffOptions);
            try {
                // Try to apply the patch.
                $this->git->apply($patch, '-3');
            } catch (TerminusException $e) {
                // Merge conflicts found, aks user to resolve merge conflicts manually.
                $this->log()->warning(
                    sprintf(
                        <<<EOD
Automatic merge has failed!
The next step in the site conversion process is to resolve the code merge conflicts manually in %s branch:
1. resolve code merge conflicts found in %s files: %s
2. commit the changes - `git add -u && git commit -m 'Copy site-specific code related to "drupal-project" upstream'`
3. run `terminus conversion:drupal-recommended %s --continue` Terminus command to continue the conversion process
EOD,
                        self::TARGET_GIT_BRANCH,
                        $this->localPath,
                        implode(', ', $this->git->diffFileList('--diff-filter=U')),
                        $this->site->getName()
                    )
                );
                exit;
            }

            $this->git->commit('Copy site-specific code related to "drupal-project" upstream');
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf(
                    'Failed detecting the differences between the site code and its upstream: %s',
                    $t->getMessage()
                )
            );
        }
    }
}
