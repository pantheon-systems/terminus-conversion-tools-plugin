<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @param string $site_id
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function convert(string $site_id)
    {
        $site = $this->getSite($site_id);

        if (!$site->isDrupal8Framework()) {
            throw new TerminusException(
                'The site {site_name} is not a Drupal 8 based site.',
                ['site_name' => $site->getName()]
            );
        }

        if (!$site->getUpstream()->isDrops8Upstream()) {
            throw new TerminusException(
                'The site {site_name} is not a "drops-8" upstream based site.',
                ['site_name' => $site->getName()]
            );
        }
    }
}
