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

    private const DRUPAL_RECOMMENDED_UPSTREAM_ID = 'drupal-recommended';
    private const DRUPAL_RECOMMENDED_GIT_REMOTE_URL = 'git@github.com:pantheon-upstreams/drupal-recommended.git';

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
        $git->addRemote(self::DROPS_8_GIT_REMOTE_URL, self::DROPS_8_UPSTREAM_ID);
        $git->fetch(self::DROPS_8_UPSTREAM_ID);
        $composerJsonDiff = $git->diff(
            '--ignore-space-change',
            '--unified=0',
            Git::DEFAULT_BRANCH,
            sprintf('%s/%s', self::DROPS_8_UPSTREAM_ID, 'default'),
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
Advice: convert the site to a Composer managed one by using `conversion:composer` Terminus command.
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
Advice: convert the site to use "drupal-recommended" Pantheon Upstream.
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
Advice: convert the site to use "drupal-recommended" Pantheon Upstream.
EOD
                );
            } else {
                // Repository contents matches "drupal-recommended" upstream.

                $git = new Git($localPath);
                $git->addRemote(self::DRUPAL_RECOMMENDED_GIT_REMOTE_URL, self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
                $git->fetch(self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
                $upstreamCommitHashes = $git->getCommitHashes(
                    sprintf('%s/%s', self::DRUPAL_RECOMMENDED_UPSTREAM_ID, Git::DEFAULT_BRANCH)
                );
                $siteCommitHashes = $git->getCommitHashes(
                    sprintf('%s/%s', Git::DEFAULT_REMOTE, 'drupal-recommended-based-branch')//Git::DEFAULT_BRANCH)
                );
                $identicalCommitHashes = array_intersect($siteCommitHashes, $upstreamCommitHashes);
                if (0 < count($identicalCommitHashes)) {
                    $this->output()->write(
                        <<<EOD
Advice: switch the upstream to "drupal-recommended" with Terminus -
`terminus site:upstream:set {$this->site->getName()} drupal-recommended`.
EOD
                    );
                    return;
                }

                $this->output()->write(
                    <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream and then switch the upstream with Terminus to
"drupal-recommended" accordingly (`terminus site:upstream:set {$this->site->getName()} drupal-recommended`).
EOD
                );
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
Advice: convert the site to a Composer managed one by using `conversion:composer {$this->site->getName()}` Terminus
command, but stay on "empty" upstream.
EOD
            );
        }
    }
}
