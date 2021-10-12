<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Helpers\Traits\CommandExecutorTrait;

/**
 * Class Composer.
 */
class Composer
{
    use CommandExecutorTrait;

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
        if (!is_file(FileSystem::buildPath($workingDirectory, 'composer.json'))) {
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
        $this->execute(sprintf('composer require %s:%s --working-dir=%s', $package, $version, $this->workingDirectory));
    }
}
