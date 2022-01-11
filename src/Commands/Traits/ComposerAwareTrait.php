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
    protected function setComposer(string $projectPath): void
    {
        $this->composer = new Composer($projectPath);
    }

    /**
     * Returns the Composer object.
     */
    protected function getComposer(): Composer
    {
        return $this->composer;
    }
}
