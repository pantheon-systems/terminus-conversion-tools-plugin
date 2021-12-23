<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\TerminusConversionTools\Utils\Git;

/**
 * Trait GitAwareTrait.
 */
trait GitAwareTrait
{
    /**
     * @var \Pantheon\TerminusConversionTools\Utils\Git
     */
    private Git $git;

    /**
     * Instantiates and sets the Git object.
     *
     * @param string $path
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function setGit(string $path): void
    {
        $this->git = new Git($path);
    }

    /**
     * Returns the Git object.
     *
     * @return \Pantheon\TerminusConversionTools\Utils\Git
     */
    private function getGit(): Git
    {
        return $this->git;
    }
}
