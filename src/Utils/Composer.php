<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException;
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
    private string $projectPath;

    /**
     * Composer constructor.
     *
     * @param string $projectPath
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function __construct(string $projectPath)
    {
        if (!is_file(Files::buildPath($projectPath, 'composer.json'))) {
            throw new TerminusException(
                'composer.json file not found in {project_path}.',
                ['project_path' => $projectPath]
            );
        }

        $this->projectPath = $projectPath;
    }

    /**
     * Executes `composer require` command.
     *
     * @param string $package
     *   The package name.
     * @param string|null $version
     *   The package version constraint.
     * @param array $options
     *   Additional options.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function require(string $package, ?string $version = null, ...$options): void
    {
        if (null !== $version) {
            $this->execute(['composer', 'require', sprintf('%s:%s', $package, $version), ...$options]);
        } else {
            $this->execute(['composer', 'require', $package, ...$options]);
        }
    }

    /**
     * Executes `composer install` command.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function install(...$options): void
    {
        $this->execute(['composer', 'install', ...$options]);
    }

    /**
     * Executes `composer update` command.
     *
     * @param array $options
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function update(...$options): void
    {
        $this->execute(['composer', 'update', ...$options]);
    }

    /**
     * Executes the Composer command.
     *
     * @param array $command
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function execute(array $command): void
    {
        try {
            $process = new Process($command, $this->projectPath, null, null, 180);
            $process->mustRun();
        } catch (Throwable $t) {
            throw new ComposerException(
                sprintf('Failed executing Composer command: %s', $t->getMessage())
            );
        }
    }

    /**
     * Returns current composer.json as an array.
     */
    public function getComposerJsonData(): array
    {
        $filePath = Files::buildPath($this->projectPath, 'composer.json');
        return json_decode(file_get_contents($filePath), true);
    }

    /**
     * Write given array as composer.json.
     */
    public function writeComposerJsonData(array $data)
    {
        $filePath = Files::buildPath($this->projectPath, 'composer.json');
        return file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_UNICODE));
    }
}
