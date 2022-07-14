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
    private string $gitignoreFilePath;

    /**
     * @var bool
     */
    private bool $isGitignoreHeaderAdded = false;

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
     *   The name or UUID of a site to operate on.
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

        $this->log()->notice('Analyzing Composer installation paths...');
        $installationPaths = $this->getComposer()->getInstallationPaths();
        $installationPathsProcessed = array_filter(
            $installationPaths,
            fn($path) => 0 !== strpos($path, 'vendor/')
        );
        array_unshift($installationPathsProcessed, ...$this->getDefaultPathsToIgnore());
        sort($installationPathsProcessed);

        // Collect all parent installation paths that have more than one installed package.
        $installationPathsParents = [];
        foreach ($installationPathsProcessed as $path) {
            $installationPathsParents[dirname($path)][] = $path;
        }
        foreach ($installationPathsParents as $pathParent => $subPaths) {
            if (count($subPaths) <= 1) {
                continue;
            }

            // Remove all installation paths that starts with $pathParent...
            $installationPathsProcessed = array_filter(
                $installationPathsProcessed,
                fn($path) => is_array($path) || 0 !== strpos($path, $pathParent)
            );
            // ...and replace with a single complex rule instead.
            $installationPathsProcessed[] = [
                'pattern' => $pathParent . '/*',
                'paths' => array_map(
                    fn($path) => str_replace($pathParent . '/', '', $path),
                    $subPaths
                ),
            ];
        }

        // Add rules for paths to .gitignore and remove the paths from git repository.
        foreach ($installationPathsProcessed as $pathRuleToIgnore) {
            if (is_string($pathRuleToIgnore)) {
                $this->addPathToIgnore($pathRuleToIgnore);
                continue;
            }

            if (is_array($pathRuleToIgnore)) {
                $this->addPathToIgnore($pathRuleToIgnore['pattern'], $pathRuleToIgnore['paths']);
            }
        }

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
     * @param string $pathPattern
     *   A path or a gitignore path pattern (i.e. /web/themes/composer/*).
     * @param array $subPaths
     *   An array of sub-paths to ignore. Used with path pattern "/*".
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function addPathToIgnore(string $pathPattern, array $subPaths = []): void
    {
        $path = str_replace('/*', '', $pathPattern);

        if (!$this->fs->exists(Files::buildPath($this->getLocalSitePath(), $path))) {
            $this->log()->notice(sprintf('Skipped adding "%s" to .gitignore file: the path does not exist.', $path));

            return;
        }

        if ($this->getGit()->isIgnoredPath($path)) {
            $this->log()->notice(
                sprintf('Skipped adding "%s" to .gitignore file: the path is already ignored.', $path)
            );

            return;
        }

        $excludes = $pathPattern !== $path ? array_diff(
            scandir(Files::buildPath($this->getLocalSitePath(), $path)),
            ['.', '..', ...$subPaths]
        ) : [];

        $excludesString = implode(
            ', ',
            array_map(fn($exclude) => Files::buildPath($path, $exclude), $excludes)
        );
        $pathMessagePart = $excludes ?
            sprintf('"%s" (excluding: "%s")', $pathPattern, $excludesString) :
            sprintf('"%s"', $pathPattern);

        if (!$this->input()->getOption('yes')
            && !$this->io()->confirm(
                sprintf(
                    'Do you want to add path %s to .gitignore and commit the changes respectively?',
                    $pathMessagePart
                )
            )
        ) {
            $this->log()->warning(
                sprintf('Skipped adding "%s" to .gitignore file: rejected by the user.', $pathPattern)
            );

            return;
        }

        $this->log()->notice(sprintf('Adding %s to .gitignore file...', $pathMessagePart));

        // Add new gitignore rule(s).
        $gitignoreFile = fopen($this->gitignoreFilePath, 'a');
        if (!$this->isGitignoreHeaderAdded) {
            fwrite($gitignoreFile, '# Added by Terminus Conversion Tools Plugin.' . PHP_EOL);
            $this->isGitignoreHeaderAdded = true;
        }
        fwrite($gitignoreFile, '/' . $pathPattern . PHP_EOL);
        foreach ($excludes as $exclude) {
            fwrite($gitignoreFile, '!/' . $path . '/' . $exclude . PHP_EOL);
        }
        fclose($gitignoreFile);

        // Commit new .gitignore rule(s).
        $this->getGit()->commit(
            sprintf('Add %s to .gitignore', $pathMessagePart),
            ['.gitignore']
        );

        // Remove path (or paths) from git.
        $removedPaths = [];
        if ($subPaths && $excludes) {
            // Remove specific paths because of excludes.
            foreach ($subPaths as $subPath) {
                $pathToRemove = $path . '/' . $subPath;
                $this->getGit()->remove('-r', '--cached', $pathToRemove);
                $removedPaths[] = $pathToRemove;
            }
        } else {
            $this->getGit()->remove('-r', '--cached', $path);
            $removedPaths[] = $path;
        }

        $removedPathsString = implode(', ', $removedPaths);
        $commitMessage = 1 === count($removedPaths) ?
            sprintf('Remove git-ignored path "%s"', $removedPathsString) :
            sprintf('Remove git-ignored paths "%s"', $removedPathsString);
        $this->getGit()->commit($commitMessage, ['-u']);
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
