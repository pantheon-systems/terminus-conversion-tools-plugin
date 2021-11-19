<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Throwable;

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
        $this->writeln(
            sprintf(
                'The site %s uses "%s" (%s) upstream.',
                $this->site->getName(),
                $this->site->getUpstream()->get('label'),
                $upstreamId
            )
        );

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

        $this->output()->writeln('Sorry, no advice is available.');
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

        try {
            $composerJsonRequireExtraPackages = [];
            $composerJsonContents = file_get_contents(Files::buildPath($localPath, 'composer.json'));
            $composerJsonRequireExtraPackages = array_keys(array_filter(
                json_decode($composerJsonContents, true)['require'],
                fn($package) => 'composer/installers' !== $package && false === strpos($package, 'drupal/core-'),
                ARRAY_FILTER_USE_KEY
            ));
        } catch (Throwable $t) {
            $this->log()->error(
                sprintf('Failed composer.json analysis: %s', $t->getMessage())
            );
        }

        if (0 < count($composerJsonRequireExtraPackages)) {
            $this->log()->notice(
                sprintf(
                    'Extra packages found in composer.json: %s.',
                    implode(', ', $composerJsonRequireExtraPackages)
                )
            );
            $this->output()->writeln('Composer used incorrectly.');
        } else {
            $this->output()->writeln('Standard drops-8 site.');
        }

        $this->output()->writeln(
            <<<EOD
Advice: convert the site to a Composer managed one by using `conversion:composer` Terminus command
(i.e. `terminus conversion:composer {$this->site->getName()}`).
EOD
        );
    }

    /**
     * Prints advice related to "drupal-project" upstream.
     */
    private function adviseOnDrupalProject(): void
    {
        $this->output()->writeln(
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
            if (false === strpos($composerJsonContent, 'drupal/core-recommended')) {
                // Repository contents matches "drupal-recommended" upstream.

                $git = new Git($localPath);
                $git->addRemote(self::DRUPAL_RECOMMENDED_GIT_REMOTE_URL, self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
                $git->fetch(self::DRUPAL_RECOMMENDED_UPSTREAM_ID);
                $upstreamCommitHashes = $git->getCommitHashes(
                    sprintf('%s/%s', self::DRUPAL_RECOMMENDED_UPSTREAM_ID, Git::DEFAULT_BRANCH)
                );
                $siteCommitHashes = $git->getCommitHashes(
                    sprintf('%s/%s', Git::DEFAULT_REMOTE, Git::DEFAULT_BRANCH)
                );
                $identicalCommitHashes = array_intersect($siteCommitHashes, $upstreamCommitHashes);
                if (0 < count($identicalCommitHashes)) {
                    $this->output()->writeln(
                        <<<EOD
Advice: switch the upstream to "drupal-recommended" with Terminus -
`terminus site:upstream:set {$this->site->getName()} drupal-recommended`.
EOD
                    );

                    return;
                }
            }

            $this->output()->writeln(
                <<<EOD
Advice: convert the site to use "drupal-recommended" Pantheon Upstream and then switch the upstream with Terminus to
"drupal-recommended" accordingly (`terminus site:upstream:set {$this->site->getName()} drupal-recommended`).
EOD
            );

            return;
        }

        if (is_file(Files::buildPath($localPath, 'build-metadata.json'))) {
            // Build artifact created by Terminus Build Tools plugin is present.

            $this->output()->writeln(
                <<<EOD
Advice: stay on "empty" upstream.
EOD
            );

            return;
        }

        $this->output()->writeln(
            <<<EOD
Advice: convert the site to a Composer managed one by using `conversion:composer` Terminus command
(i.e. `terminus conversion:composer {$this->site->getName()}`), but stay on "empty" upstream.
EOD
        );
    }
}
