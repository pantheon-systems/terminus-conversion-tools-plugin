<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;

/**
 * Class ConvertToComposerSiteCommand.
 */
class ConvertToComposerSiteCommand extends TerminusCommand
{
    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:composer
     *
     * @param string $site_id
     */
    public function convert(string $site_id)
    {
        $this->log()->notice('Hello world, {site_id}!', ['site_id' => $site_id]);
    }
}
