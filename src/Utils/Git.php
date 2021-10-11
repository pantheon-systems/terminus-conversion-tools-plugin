<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\Traits\CommandExecutorTrait;
use Throwable;

/**
 * Class Git.
 *
 * @todo: replace with Symfony Process component.
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
     * Returns TRUE is there is anything to commit.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function isAnythingToCommit(): bool
    {
        [$output] = $this->execute(sprintf('git -C %s status --porcelain', $this->workingDirectory));

        return '' !== $output;
    }

    /**
     * Performs force push of the branch.
     *
     * @param string $branchName
     *   The branch name.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function push(string $branchName): void
    {
        $this->execute(sprintf('git -C %s push %s %s', $this->workingDirectory, $this->remote, $branchName));
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

    /**
     * Adds remote.
     *
     * @param string $remote
     * @param string $name
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function addRemote(string $remote, string $name)
    {
        $this->execute(sprintf('git -C %s remote add %s %s', $this->workingDirectory, $name, $remote));
    }

    /**
     * Fetches from the remote.
     *
     * @param string $remote
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function fetch(string $remote)
    {
        $this->execute(sprintf('git -C %s fetch %s', $this->workingDirectory, $remote));
    }

    /**
     * Performs checkout operation.
     *
     * @param string $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function checkout(string $options)
    {
        $this->execute(sprintf('git -C %s checkout %s', $this->workingDirectory, $options));
    }

    /**
     * Move files.
     *
     * @param string $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function move(string $options)
    {
        $this->execute(sprintf('git -C %s mv %s', $this->workingDirectory, $options));
    }

    /**
     * Removes files.
     *
     * @param string $options
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function remove(string $options)
    {
        $this->execute(sprintf('git -C %s rm %s', $this->workingDirectory, $options));
    }

    /**
     * Deletes remote branch.
     *
     * @param string $branch
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function deleteRemoteBranch(string $branch)
    {
        $this->execute(sprintf('git -C %s push origin --delete %s', $this->workingDirectory, $branch));
    }
}
