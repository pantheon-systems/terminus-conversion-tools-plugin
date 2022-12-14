<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\TerminusConversionTools\Utils\Files;
use Pantheon\TerminusConversionTools\Utils\Git;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class ConversionCommandsValidateAndFixGitignoreTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-non-ic custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsValidateAndFixGitignoreTest extends ConversionCommandsTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_NON_IC_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     *
     * @group validate_and_fix_gitignore_command
     *
     * @throws \Pantheon\TerminusConversionTools\Exceptions\Git\GitException
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function testConversionCommands(): void
    {
        $localSiteDirName = sprintf('%s_terminus_conversion_plugin', $this->siteName);
        $localSitePath = Files::buildPath(
            $_SERVER['HOME'],
            'pantheon-local-copies',
            $localSiteDirName
        );

        // Execute the command.
        $this->terminus(sprintf('conversion:validate-gitignore %s', $this->siteName));

        // Verify .gitignore file.
        $gitignoreFilePath = Files::buildPath($localSitePath, '.gitignore');
        $this->assertTrue(
            is_file($gitignoreFilePath),
            sprintf('File "%s" must exist', $gitignoreFilePath)
        );
        $this->assertEquals(
            <<<EOD
# Added by Terminus Conversion Tools Plugin.
/vendor
/web/core
/web/themes/composer/bootstrap
/web/modules/composer/*

EOD,
            file_get_contents($gitignoreFilePath)
        );

        $git = new Git($localSitePath);
        $fs = new Filesystem();

        // Before deleting Composer-installed assets.
        $this->assertEmpty($git->diffFileList(), 'Git diff result must be empty.');

        // After deleting some Composer-installed assets.
        $fs->remove(Files::buildPath($localSitePath, 'vendor'));
        $fs->remove(Files::buildPath($localSitePath, 'web/core'));
        $fs->remove(Files::buildPath($localSitePath, 'web/modules/composer'));
        $this->assertEmpty(
            $git->diffFileList(),
            'Git diff result must be empty after deleting Composer-installed assets.'
        );

        // After deleting some Composer-installed assets and a non-gitignored test file.
        $fs->remove(Files::buildPath($localSitePath, 'web/themes/composer'));
        $this->assertEquals(
            ['web/themes/composer/this_file_must_not_be_gitignored.txt'],
            $git->diffFileList(),
            'Git diff must have exactly one deleted file "web/themes/composer/this_file_must_not_be_gitignored.txt"'
        );
    }
}
