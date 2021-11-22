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

    /**
     * @var string
     */
    private string $localPath;

    /**
     * Converts a "drupal-project" upstream-based site into "drupal-recommended" upstream-based one.
     *
     * @todo: update `conversion:advise` command advice message for a "drupal-project" upstream-based site.
     *
     * @command conversion:drupal-recommended
     *
     * @option branch The target branch name for multidev env.
     * @option dry-run Skip creating multidev and pushing "drupal-rec" branch.
     *
     * @param string $site_id
     * @param array $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function convert(
        string $site_id,
        array $options = ['branch' => self::TARGET_GIT_BRANCH, 'dry-run' => false]
    ): void {
        $this->site = $this->getSite($site_id);

        $upstream_id = $this->site->getUpstream()->get('machine_name');
        if (self::DRUPAL_PROJECT_UPSTREAM_ID !== $upstream_id) {
            throw new TerminusException(
                'The site {site_name} is not a "drupal-project" upstream-based site.',
                ['site_name' => $this->site->getName()]
            );
        }

        $branch = $options['branch'];
        if (strlen($branch) > 11) {
            throw new TerminusException(
                'The target git branch name for multidev env must not exceed 11 characters limit'
            );
        }

        $this->localPath = $this->cloneSiteGitRepository();

        $this->git = new Git($this->localPath);
        $composer = new Composer($this->localPath);

        $this->log()->notice(sprintf('Creating "%s" git branch...', $branch));
        $this->git->checkout('-b', $branch, Git::DEFAULT_REMOTE . '/' . Git::DEFAULT_BRANCH);
        $this->git->addRemote(self::TARGET_UPSTREAM_GIT_REMOTE_URL, self::TARGET_UPSTREAM_GIT_REMOTE_NAME);
        $this->git->fetch(self::TARGET_UPSTREAM_GIT_REMOTE_NAME);

        $this->git->checkout(
            self::TARGET_UPSTREAM_GIT_REMOTE_NAME . '/' . Git::DEFAULT_BRANCH,
            'upstream-configuration'
        );
        $this->git->checkout(
            self::TARGET_UPSTREAM_GIT_REMOTE_NAME . '/' . Git::DEFAULT_BRANCH,
            'pantheon.upstream.yml'
        );

        $this->updateComposerJsonMeta();

        $this->log()->notice('Adding composer dependencies...');
        try {
            foreach ($this->getComposerDependencies() as $dependency) {
                $arguments = [$dependency['package'], $dependency['version'], '--no-update'];
                if ($dependency['is_dev']) {
                    $arguments[] = '--dev';
                }

                $composer->require(...$arguments);
                $this->log()->notice(sprintf('%s (%s) is added', $dependency['package'], $dependency['version']));
            }

            $this->log()->notice('Updating composer dependencies...');

            $composer->update();
        } catch (Throwable $t) {
            $this->log()->warning(
                sprintf(
                    'Failed updating composer dependencies: %s',
                    $t->getMessage()
                )
            );
        }

        if ($this->git->isAnythingToCommit()) {
            $this->git->commit('Convert to "drupal-recommended" upstream');
            $this->log()->notice('Codebase converted to match "drupal-recommended" upstream');
        }

        if (!$options['dry-run']) {
            try {
                $this->deleteMultidevIfExists($branch);
            } catch (TerminusCancelOperationException $e) {
                return;
            }

            $this->log()->notice(sprintf('Pushing changes to "%s" git branch...', $branch));
            $this->git->push($branch);
            $mdEnv = $this->createMultidev($branch);

            $this->log()->notice(
                sprintf('Link to "%s" multidev environment dashboard: %s', $branch, $mdEnv->dashboardUrl())
            );
        }
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
        return [
            [
                'package' => 'drupal/core-recommended',
                'version' => '^9.2',
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-composer-scaffold',
                'version' => '^9.2',
                'is_dev' => false,
            ],
            [
                'package' => 'composer/installers',
                'version' => '^1.9',
                'is_dev' => false,
            ],
            [
                'package' => 'pantheon-systems/drupal-integrations',
                'version' => '^9',
                'is_dev' => false,
            ],
            [
                'package' => 'cweagans/composer-patches',
                'version' => '^1.7',
                'is_dev' => false,
            ],
            [
                'package' => 'drupal/core-dev',
                'version' => '^9.2',
                'is_dev' => true,
            ],
        ];
    }
}
