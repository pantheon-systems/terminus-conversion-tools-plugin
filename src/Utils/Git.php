<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitMergeConflictException;
use Pantheon\TerminusConversionTools\Exceptions\Git\GitNoDiffException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class Git.
 */
class Git
{
    /**
     * @var string
     */
    private string $repoPath;

    public const DEFAULT_REMOTE = 'origin';
    public const DEFAULT_BRANCH = 'master';

    /**
     * Git constructor.
     *
     * @param string $repoPath
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $repoPath)
    {
        $this->repoPath = $repoPath;

        try {
            $this->execute(['status']);
        } catch (Throwable $t) {
            throw new TerminusException(
                'Failed verify that {repo_path} is a valid Git repository: {error_message}',
                ['repo_path' => $repoPath, 'error_message' => $t->getMessage()]
            );
        }
    }

    /**
     * Returns the list of .gitignore rules existing in the base file and missing in the file to compare with.
     *
     * @param string $baseFilePath
     *   The path to the base .gitignore file.
     * @param string $fileToComparePath
     *   The path to the .gitignore file to compare with.
     *
     * @return array
     *   The list of .gitignore rules.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public static function getGitignoreDiff(string $baseFilePath, string $fileToComparePath): array
    {
        if (!is_file($baseFilePath)) {
            throw new GitException(sprintf('Missing base .gitignore file (%s).', $baseFilePath));
        }
        if (!is_file($fileToComparePath)) {
            throw new GitException(sprintf('Missing .gitignore file to compare with (%s).', $fileToComparePath));
        }

        $filter = fn($rule) => false === strpos($rule, '#');
        $baseRules = file($baseFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $baseRules = array_filter($baseRules, $filter);

        $compareRules = file($fileToComparePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $compareRules = array_filter($compareRules, $filter);

        return array_values(array_diff($baseRules, $compareRules));
    }

    /**
     * Commits the changes.
     *
     * @param string $commitMessage
     *   The commit message.
     * @param null|array $gitAddOptions
     *   git-add options.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function commit(string $commitMessage, ?array $gitAddOptions = null): void
    {
        if (null === $gitAddOptions) {
            $this->add('-A');
        } else {
            $this->add(...$gitAddOptions);
        }

        $this->execute(['commit', '-m', $commitMessage]);
    }

    /**
     * Applies the patch provided in a form of `git diff` options using 3-way merge technique.
     *
     * @param array $diffOptions
     * @param string ...$options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitMergeConflictException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitNoDiffException
     */
    public function apply(array $diffOptions, ...$options): void
    {
        if (!$this->diffFileList(...$diffOptions)) {
            throw new GitNoDiffException(
                sprintf('No diff returned by `git diff %s`', implode(' ', $diffOptions))
            );
        }

        $patch = $this->diff(...$diffOptions);
        try {
            $this->execute(['apply', '--3way', ...$options], $patch);
        } catch (GitException $e) {
            if (1 !== preg_match('/Applied patch to \'(.+)\' with conflicts/', $e->getMessage())) {
                throw $e;
            }

            $unmergedFiles = $this->diffFileList('--diff-filter=U');
            throw new GitMergeConflictException(
                sprintf('Merge conflicts in files: %s', implode(', ', $unmergedFiles)),
                0,
                null,
                $unmergedFiles
            );
        }
    }

    /**
     * Returns TRUE is there is anything to commit.
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function isAnythingToCommit(): bool
    {
        return '' !== $this->execute(['status', '--porcelain']);
    }

    /**
     * Performs force push of the branch.
     *
     * @param string $branchName
     *   The branch name.
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function push(string $branchName, ...$options): void
    {
        $this->execute(['push', self::DEFAULT_REMOTE, $branchName, ...$options]);
    }

    /**
     * Performs merge operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function merge(...$options): void
    {
        $this->execute(['merge', ...$options]);
    }

    /**
     * Returns TRUE if the branch exists in the remote.
     *
     * @param string $branch
     *   The branch name.
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function isRemoteBranchExists(string $branch): bool
    {
        return '' !== trim($this->execute(['ls-remote', self::DEFAULT_REMOTE, $branch]));
    }

    /**
     * Adds remote.
     *
     * @param string $remote
     * @param string $name
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function addRemote(string $remote, string $name)
    {
        $process = $this->executeAndReturnProcess(['remote', 'show', $name]);
        if (0 === $process->getExitCode()) {
            $this->execute(['remote', 'set-url', $name, $remote]);
        } else {
            $this->execute(['remote', 'add', $name, $remote]);
        }
        $this->execute(['remote', 'set-url', $name, $remote]);
    }

    /**
     * Fetches from the remote.
     *
     * @param string $remoteName
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function fetch(string $remoteName)
    {
        $this->execute(['fetch', $remoteName]);
    }

    /**
     * Performs checkout operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function checkout(...$options)
    {
        $this->execute(['checkout', ...$options]);
    }

    /**
     * Move files.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function move(...$options)
    {
        $this->execute(sprintf('git mv %s', implode(' ', $options)));
    }

    /**
     * Removes files.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function remove(...$options)
    {
        $this->execute(['rm', ...$options]);
    }

    /**
     * Performs reset operation.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function reset(...$options)
    {
        $this->execute(['reset', ...$options]);
    }

    /**
     * Returns the result of `git diff` command.
     *
     * @param array $options
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function diff(...$options): string
    {
        return $this->execute(['diff', ...$options]);
    }

    /**
     * Returns the result of `git diff` command as a list of files affected.
     *
     * @param mixed ...$options
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function diffFileList(...$options): array
    {
        return array_filter(
            explode(PHP_EOL, $this->diff('--name-only', ...$options))
        );
    }

    /**
     * Deletes remote branch.
     *
     * @param string $branch
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function deleteRemoteBranch(string $branch)
    {
        $this->execute(['push', self::DEFAULT_REMOTE, '--delete', $branch]);
    }

    /**
     * Returns HEAD commit hash value of the specified remote branch.
     *
     * @param string $branch
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function getHeadCommitHash(string $branch): string
    {
        $hash = trim(
            $this->execute(['log', '--format=%H', '-n', '1', sprintf('%s/%s', self::DEFAULT_REMOTE, $branch)])
        );
        if (preg_match('/^[0-9a-f]{40}$/i', $hash)) {
            return $hash;
        }

        throw new GitException(sprintf('"%s" is not a valid sha1 commit hash value', $hash));
    }

    /**
     * Returns the list of commit hashes for the branch.
     *
     * @param string $branch
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function getCommitHashes(string $branch): array
    {
        $commitHashes = $this->execute(['log', $branch, '--pretty=format:%H']);

        return preg_split('/\r\n|\n|\r/', $commitHashes);
    }

    /**
     * Returns Git config value by the config name.
     *
     * @param string $confName
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function getConfig(string $confName): string
    {
        return trim($this->execute(['config', '--get', $confName]));
    }

    /**
     * Returns the top-level repository path.
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function getToplevelRepoPath(): string
    {
        return trim($this->execute(['rev-parse', '--show-toplevel']));
    }

    /**
     * Returns TRUE if the path is an ignored path.
     *
     * @param string $path
     *
     * @return bool
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function isIgnoredPath(string $path): bool
    {
        $process = $this->executeAndReturnProcess(['check-ignore', '--quiet', $path]);
        switch ($process->getExitCode()) {
            case 0:
                return true;
            case 1:
                return false;
            default:
                throw new GitException(
                    sprintf('Failed to check if path "%s" is ignored: exit code %d', $path, $process->getExitCode())
                );
        }
    }

    /**
     * Append given path to .gitignore.
     *
     * @param string $path
     *   Path to ignore.
     */
    public function appendToIgnore(string $path): void
    {
        $gitignoreFile = fopen(Files::buildPath($this->repoPath, '.gitignore'), 'a');
        fwrite($gitignoreFile, $path . PHP_EOL);
        $this->commit(sprintf('Add %s to gitignore.', $path), ['.gitignore']);
    }

    /**
     * Adds files to index.
     *
     * @param $options
     *   The list of files and git-add options.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function add(...$options): void
    {
        $this->execute(['add', ...$options]);
    }

    /**
     * Executes the Git command.
     *
     * @param array|string $command
     * @param null|string $input
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function execute($command, ?string $input = null): string
    {
        try {
            $process = $this->executeAndReturnProcess($command, $input);
            if (0 !== $process->getExitCode()) {
                throw new GitException(
                    sprintf(
                        'Git command failed with exit code %d and message %s',
                        $process->getExitCode(),
                        $process->getErrorOutput()
                    )
                );
            }
        } catch (Throwable $t) {
            throw new GitException(
                sprintf('Failed executing Git command: %s', $t->getMessage())
            );
        }

        return $process->getOutput();
    }

    /**
     * Executes the Git command and return the process object.
     *
     * @param array|string $command
     * @param null|string $input
     *
     * @return \Symfony\Component\Process\Process
     */
    private function executeAndReturnProcess($command, ?string $input = null): Process
    {
        if (is_string($command)) {
            $process = Process::fromShellCommandline($command, $this->repoPath);
        } else {
            $process = new Process(['git', ...$command], $this->repoPath, null, $input, 180);
        }
        $process->run();
        return $process;
    }
}
