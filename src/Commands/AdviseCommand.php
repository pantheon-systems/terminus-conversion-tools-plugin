<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
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
        }
    }

    /**
     * Prints advice related to Drops-8 upstream.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusAlreadyExistsException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Pantheon\Terminus\Exceptions\TerminusNotFoundException
     */
    private function adviseOnDrops8(): void
    {
        $localPath = $this->cloneSiteGitRepository();
        $git = new Git($localPath);

        $this->log()->notice(
            <<<EOD
Advise: convert the site to support Pantheon Integrated Composer (https://pantheon.io/docs/integrated-composer).
EOD
        );

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
            $this->log()->warning('Composer used incorrectly');
        }
    }
}
