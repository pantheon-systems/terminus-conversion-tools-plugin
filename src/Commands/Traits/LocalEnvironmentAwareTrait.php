<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

/**
 * Trait LocalEnvironmentAwareTrait.
 */
trait LocalEnvironmentAwareTrait
{
    /**
     * @var bool
     */
    private bool $isLando;

    /**
     * @var string
     */
    private string $terminusCommand;

    /**
     * Instantiates and sets the Git object.
     *
     * @param string $path
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function isLando(): bool
    {
        if (empty($this->isLando)) {
            $this->isLando = (bool) getenv('LANDO_APP_NAME');
        }
        return $this->isLando;
    }

    /**
     * Returns the terminus command based on whether it's running on lando or not.
     *
     * @return string
     */
    protected function getTerminusCommand(): string
    {
        if (empty($this->terminusCommand)) {
            $this->terminusCommand = $this->isLando() ? 'lando terminus' : 'terminus';
        }
        return $this->terminusCommand;
    }
}
