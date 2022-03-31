<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\TerminusConversionTools\Utils\Files;

/**
 * Class ConversionCommandsValidateAndFixGitignoreTest.
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
     */
    public function testConversionCommands(): void
    {
        $this->terminus(sprintf('conversion:validate-gitignore %s', $this->siteName));

        $localSiteCopyDirName = sprintf('%s_terminus_conversion_plugin', $this->siteName);
        $gitignoreFilePath = Files::buildPath(
            $_SERVER['HOME'],
            'pantheon-local-copies',
            $localSiteCopyDirName,
            '.gitignore'
        );
        $this->assertTrue(is_file($gitignoreFilePath), $gitignoreFilePath . ' .gitignore file must exist');
        $this->assertEquals('haha', file_get_contents($gitignoreFilePath));
    }
}
