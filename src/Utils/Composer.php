<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Class Composer.
 */
class Composer
{
    /**
     * @var string
     */
    private string $workingDirectory;

    /**
     * Composer constructor.
     *
     * @param string $workingDirectory
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $workingDirectory)
    {
        if (!is_file(Files::buildPath($workingDirectory, 'composer.json'))) {
            throw new TerminusException(
                'composer.json file not found in {working_directory}.',
                ['working_directory' => $workingDirectory]
            );
        }

        $this->workingDirectory = $workingDirectory;
    }

    /**
     * Executes `composer require` command.
     *
     * @param string $package
     *   The package name.
     * @param string $version
     *   The package version constraint.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function require(string $package, string $version): void
    {
        $this->execute(['composer', 'require', sprintf('%s:%s', $package, $version)]);
    }

    /**
     * Executes the Composer command.
     *
     * @param array $command
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function execute(array $command): void
    {
        try {
            $process = new Process($command, $this->workingDirectory);
            $process->mustRun();
        } catch (Throwable $t) {
            throw new TerminusException(
                sprintf('Failed executing Composer command: %s', $t->getMessage())
            );
        }
    }
}
