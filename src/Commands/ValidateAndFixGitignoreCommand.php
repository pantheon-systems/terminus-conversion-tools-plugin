<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException;
use Pantheon\TerminusConversionTools\Utils\Files;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class ValidateAndFixGitignoreCommand.
 */
class ValidateAndFixGitignoreCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $fs;

    /**
     * @var string
     */
    private $gitignoreFilePath;

    /**
     * @var bool
     */
    private $isGitignoreHeaderAdded = false;

    /**
     * ValidateAndFixGitignoreCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->fs = new Filesystem();
    }

    /**
     * Validates Git/Composer project and suggests tweaks to .gitignore file.
     *
     * @command conversion:validate-gitignore
     *
     * @param string $site_id
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Composer\ComposerException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    public function validateAndFixGitignore(string $site_id): void
    {
        $this->setSite($site_id);

        $this->setComposer($this->getLocalSitePath());
        $this->log()->notice('Installing Composer dependencies...');
        $this->getComposer()->install();

        $this->gitignoreFilePath = Files::buildPath($this->getLocalSitePath(), '.gitignore');
        $this->setGit($this->getLocalSitePath());
        $this->validateGitignoreExists();

        $installationPaths = $this->getComposer()->getInstallationPaths();
        $installationPathsProcessed = array_filter($installationPaths, fn($path) => 0 !== strpos($path, 'vendor/'));
        array_unshift($installationPathsProcessed, ...$this->getDefaultPathsToIgnore());
        sort($installationPathsProcessed);
        array_walk($installationPathsProcessed, [$this, 'addPathToIgnore']);

        $this->log()->notice('Done!');
    }

    /**
     * Validates the .gitignore file exists, otherwise suggests creating it automatically.
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    protected function validateGitignoreExists(): void
    {
        if (is_file($this->gitignoreFilePath)) {
            return;
        }

        if (!$this->input()->getOption('yes')
            && !$this->io()->confirm(
                sprintf(
                    'File .gitignore not found in "%s". Do you want to create and commit it?',
                    $this->getLocalSitePath()
                )
            )
        ) {
            throw new TerminusCancelOperationException(
                sprintf(
                    'Create .gitignore file in "%s" operation has not been confirmed.',
                    $this->getLocalSitePath()
                )
            );
        }

        if (false === file_put_contents($this->gitignoreFilePath, '')) {
            throw new TerminusException(
                sprintf('Failed to create .gitignore file in "%s".', $this->getLocalSitePath())
            );
        }
        $this->getGit()->commit('Add .gitignore', ['.gitignore']);
    }

    /**
     * Adds a path to .gitignore.
     *
     * @param string $path
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     */
    private function addPathToIgnore(string $path): void
    {
        if (!$this->fs->exists(Files::buildPath($this->getLocalSitePath(), $path))) {
            $this->log()->notice(sprintf('Skipped adding "%s" to .gitignore file: the path does not exist.', $path));

            return;
        }

        if ($this->getGit()->isIgnoredPath($path)) {
            $this->log()->notice(sprintf('Skipped adding "%s" to .gitignore file: the path is already ignored.', $path));

            return;
        }

        if (!$this->input()->getOption('yes')
            && !$this->io()->confirm(
                sprintf(
                    'Do you want to add path "%s" to .gitignore and commit the changes respectively?',
                    $path
                )
            )
        ) {
            $this->log()->warning(sprintf('Skipped adding "%s" to .gitignore file: rejected by the user.', $path));

            return;
        }

        $this->log()->notice(sprintf('Adding "%s" to .gitignore file...', $path));

        $gitignoreFile = fopen($this->gitignoreFilePath, 'a');
        if (!$this->isGitignoreHeaderAdded) {
            fwrite($gitignoreFile, '# Added by Terminus Conversion Tools Plugin.' . PHP_EOL);
            $this->isGitignoreHeaderAdded = true;
        }
        fwrite($gitignoreFile, '/' . $path . PHP_EOL);
        fclose($gitignoreFile);

        $this->getGit()->commit(sprintf('Add "%s" to .gitignore', $path), ['.gitignore']);

        $this->getGit()->remove('-r', '--cached', $path);
        $this->getGit()->commit(sprintf('Remove ignored path "%s"', $path), ['-u']);
    }

    /**
     * Returns the list of paths to ignore by default.
     *
     * @return string[]
     */
    private function getDefaultPathsToIgnore(): array
    {
        return ['vendor'];
    }
}
