<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\Traits\CommandExecutorTrait;
use Throwable;

/**
 * Class Git.
 */
class Git
{
    use CommandExecutorTrait;

    /**
     * @var string
     */
    private string $workingDirectory;

    /**
     * @var string
     */
    private string $remote;

    /**
     * Git constructor.
     *
     * @param string $workingDirectory
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $workingDirectory)
    {
        try {
            $this->execute(sprintf('git -C %s status', $workingDirectory));
        } catch (Throwable $t) {
            throw new TerminusException(
                'Failed verify that {working_directory} is a valid Git repository: {error_message}',
                ['working_directory' => $workingDirectory, 'error_message' => $t->getMessage()]
            );
        }

        $this->workingDirectory = $workingDirectory;
        $this->remote = 'origin';
    }

    /**
     * Creates and checks-out the branch.
     *
     * @param string $branch
     *  The name of the branch.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function createAndCheckoutBranch(string $branch): void
    {
        $this->execute(sprintf('git -C %s checkout -b %s', $this->workingDirectory, $branch));
    }

    /**
     * Commits the changes.
     *
     * @param string $commitMessage
     *   The commit message.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function commit(string $commitMessage): void
    {
        $this->execute(sprintf('git -C %s add -A', $this->workingDirectory));
        $this->execute(sprintf('git -C %s commit -m "%s"', $this->workingDirectory, $commitMessage));
    }

    /**
     * Performs force push of the branch.
     *
     * @param string $branchName
     *   The branch name.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function forcePush(string $branchName): void
    {
        $this->execute(sprintf('git -C %s push %s %s --force', $this->workingDirectory, $this->remote, $branchName));
    }

    /**
     * Returns TRUE if the branch exists in the remote.
     *
     * @param string $branch
     *   The branch name.
     *
     * @return bool
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function isRemoteBranchExists(string $branch): bool
    {
        [$output] = $this->execute(
            sprintf('git -C %s ls-remote %s %s', $this->workingDirectory, $this->remote, $branch)
        );

        return '' !== trim($output);
    }
}
