<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Class AdviseCommand.
 */
class AdviseCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;
    use ConversionCommandsTrait;

    private const DROPS_8_UPSTREAM_ID = 'drupal8';
    private const DROPS_8_GIT_REMOTE_URL = 'https://github.com/pantheon-systems/drops-8.git';

    private const DRUPAL_PROJECT_UPSTREAM_ID = 'drupal9';

    private const EMPTY_UPSTREAM_ID = 'empty';

    /**
     * Analyze the current state of the site and give advice on the next steps.
     *
     * @command conversion:advise
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function advise(string $site_id): void
    {
        $this->site = $this->getSite($site_id);
        $upstreamId = $this->site->getUpstream()->get('machine_name');
        $this->log()->notice(sprintf('The site %s uses %s upstream.', $this->site->getName(), $upstreamId));

        if (self::DROPS_8_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrops8();
            return;
        }

        if (self::DRUPAL_PROJECT_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnDrupalProject();
            return;
        }

        if (self::EMPTY_UPSTREAM_ID === $upstreamId) {
            $this->adviseOnEmpty();
            return;
        }

        $this->output()->write('Sorry, no advice is available.');
    }

    /**
     * Prints advice related to "drops-8" upstream.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function adviseOnDrops8(): void
    {
        $localPath = $this->cloneSiteGitRepository();
        $git = new Git($localPath);
        $git->addRemote(self::DROPS_8_GIT_REMOTE_URL, 'drops-8');
        $git->fetch('drops-8');
        $composerJsonDiff = $git->diff(
            '--ignore-space-change',
            '--unified=0',
            Git::DEFAULT_BRANCH,
            sprintf('%s/%s', 'drops-8', 'default'),
            'composer.json'
        );
        if ($composerJsonDiff) {
            $this->log()->notice(
                sprintf(
                    'Differences in composer.json between the site code and the upstream code found: %s',
                    $composerJsonDiff
                )
            );
            $this->output()->write('Composer used incorrectly.');
        } else {
            $this->output()->write('Standard drops-8 site.');
        }

        $this->output()->write(
            <<<EOD
Advice: convert the site to using `conversion:composer` Terminus command.
EOD
        );
    }

    /**
     * Prints advice related to "drupal-project" upstream.
     */
    private function adviseOnDrupalProject(): void
    {
        $this->output()->write(
            <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream
(https://github.com/pantheon-systems/drupal-recommended).
EOD
        );
    }

    /**
     * Prints advice related to "empty" upstream.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function adviseOnEmpty(): void
    {
        $localPath = $this->cloneSiteGitRepository();

        $upstreamConfComposerJsonPath = Files::buildPath($localPath, 'upstream-configuration', 'composer.json');
        if (is_file($upstreamConfComposerJsonPath)) {
            // Repository contents matches either "drupal-project" or "drupal-recommended" upstream.

            $composerJsonContent = file_get_contents($upstreamConfComposerJsonPath);
            if (false !== strpos($composerJsonContent, 'drupal/core-recommended')) {
                // Repository contents matches "drupal-project" upstream.
                $this->output()->write(
                    <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream
(https://github.com/pantheon-systems/drupal-recommended).
EOD
                );
            } else {
                // Repository contents matches "drupal-recommended" upstream.
            }

            return;
        }

        if (is_file(Files::buildPath($localPath, 'build-metadata.json'))) {
            // Build artifact created by Terminus Build Tools plugin is present.
            $this->output()->write(
                <<<EOD
Advice: stay on "empty" upstream.
EOD
            );
        } else {
            $this->output()->write(
                <<<EOD
Advice: convert the site to using `conversion:composer` Terminus command,
stay on empty upstream.
EOD
            );
        }
    }
}
