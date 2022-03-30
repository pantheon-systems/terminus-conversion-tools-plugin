<?php

namespace Pantheon\TerminusConversionTools\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\TerminusConversionTools\Commands\Traits\ComposerAwareTrait;
use Pantheon\TerminusConversionTools\Commands\Traits\ConversionCommandsTrait;
use Pantheon\TerminusConversionTools\Exceptions\TerminusCancelOperationException;
use Pantheon\TerminusConversionTools\Utils\Files;

/**
 * Class ValidateAndFixGitignoreCommand.
 */
class ValidateAndFixGitignoreCommand extends TerminusCommand implements SiteAwareInterface
{
    use ConversionCommandsTrait;
    use ComposerAwareTrait;

    /**
     * @var string
     */
    private $gitignoreFilePath;

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
     */
    public function validateAndFixGitignore(string $site_id): void
    {
        $this->setSite($site_id);

        $this->gitignoreFilePath = Files::buildPath($this->getLocalSitePath(), '.gitignore');
        $this->validateGitignoreExists();

        $this->setGit($this->getLocalSitePath());

        $this->setComposer($this->getLocalSitePath());
        $this->log()->notice('Installing Composer dependencies...');
        $this->getComposer()->install();
        $installationPaths = $this->getComposer()->getInstallationPaths();

        // 1. clone site
        //    @todo: provide a path to local cloned copy if exists
        // 2. check for existing .gitignore file
        // 3. install dependencies
        // 4. analyze
        //    - "vendor" must be always ignored
        // 5. suggest fixes
        // 6. confirm fixes
        // 7. apply fixes

        $this->log()->notice('Done!');
    }

    /**
     * Validates the .gitignore file exists, otherwise suggests creating it automatically.
     *
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
                    'File .gitignore not found in "%s". Do you want to create it?',
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

        if (!file_put_contents($this->gitignoreFilePath, '# Created by Terminus Conversion Tools Plugin.' . PHP_EOL)) {
            throw new TerminusException(
                sprintf('Failed to create .gitignore file in "%s".', $this->getLocalSitePath())
            );
        }
    }
}
