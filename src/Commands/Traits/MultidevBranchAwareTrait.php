<?php

namespace Pantheon\TerminusConversionTools\Commands\Traits;

use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Trait MultidevBranchAwareTrait.
 */
trait MultidevBranchAwareTrait
{
    /**
     * @var string
     */
    private string $branch;

    /**
     * Sets the multidev git branch.
     *
     * @param string $branch
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setBranch(string $branch): void
    {
        if (strlen($branch) > 11) {
            throw new TerminusException('The git branch name for a multidev env must not exceed 11 characters limit.');
        }

        $this->branch = $branch;
    }

    /**
     * Returns the multidev git branch.
     *
     * @return string
     */
    protected function getBranch(): string
    {
        return $this->branch;
    }
}
