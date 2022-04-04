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
     * Executes `composer remove` command.
     *
     * @param string $package
     *   The package name.
     * @param array $options
     *   Additional options.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function remove(string $package, ...$options): void
    {
        $this->execute(['composer', 'remove', $package, ...$options]);
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
     * Returns the list of relative installation paths.
     *
     * @return array
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function getInstallationPaths(): array
    {
        $pathsJson = $this->execute(['composer', 'info', '--path', '--format=json']);

        $paths = json_decode($pathsJson, true);
        if (json_last_error()) {
            throw new ComposerException(sprintf('Failed decoding JSON string: error code %d', json_last_error()));
        }

        if (!isset($paths['installed'])) {
            return [];
        }

        $installationPaths = array_column($paths['installed'], 'path');
        $installationPaths = array_filter($installationPaths, fn($path) => $path !== $this->projectPath);

        return array_map(
            fn($path) => str_replace($this->projectPath . '/', '', $path),
            $installationPaths
        );
    }

    /**
     * Executes the Composer command.
     *
     * @param array $command
     *
     * @return string
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    private function execute(array $command): string
    {
        try {
            $process = new Process($command, $this->projectPath, null, null, 180);
            $process->mustRun();

            return $process->getOutput();
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
     * Writes given array as composer.json file.
     *
     * @param array $data
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     */
    public function writeComposerJsonData(array $data): void
    {
        $filePath = Files::buildPath($this->projectPath, 'composer.json');
        $dataEncoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;
        if (file_put_contents($filePath, $dataEncoded)) {
            return;
        }

        throw new ComposerException(sprintf('Failed writing composer.json to %', $filePath));
    }
}
