<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;

/**
 * Class D8ComposerCommand.
 */
class D8ComposerCommand extends TerminusCommand
{
    /**
     * Convert a standard Drupal8 site into a Drupal8 site managed by Composer.
     *
     * @command conversion:d8composer
     * @aliases d8composer
     *
     * @param string $site_id
     */
    public function convert(string $site_id)
    {
        $this->log()->notice('Hello world, {site_id}!', ['site_id' => $site_id]);
    }
}
