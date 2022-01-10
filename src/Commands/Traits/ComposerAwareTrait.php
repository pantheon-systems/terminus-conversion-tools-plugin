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
     * @param string $projectPath
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function setComposer(string $projectPath): void
    {
        $this->composer = new Composer($projectPath);
    }

    /**
     * Returns the Composer object.
     */
    private function getComposer(): Composer
    {
        return $this->composer;
    }
}
