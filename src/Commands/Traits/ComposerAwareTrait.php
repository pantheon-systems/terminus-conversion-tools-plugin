<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\TerminusConversionTools\Utils\Composer;

/**
 * Trait ComposerAwareTrait.
 */
trait ComposerAwareTrait
{
    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Composer
     */
    private Composer $composer;

    /**
     * Instantiates and sets the Composer object.
     *
     * @param string $path
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function setComposer(string $path): void
    {
        $this->composer = new Composer($path);
    }

    /**
     * Returns the Composer object.
     */
    private function getComposer(): Composer
    {
        return $this->composer;
    }
}
