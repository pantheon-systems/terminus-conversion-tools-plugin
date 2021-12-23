<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsEnableIntegratedComposerTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-non-ic custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionCommandsEnableIntegratedComposerTest extends ConversionCommandsUpstreamTestBase
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
     */
    protected function getRealUpstreamId(): string
    {
        return 'empty';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'n/a';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return 'n/a';
    }

    /**
     * @inheritdoc
     *
     * @group enable_integrated_composer_command
     */
    public function testConversionCommands(): void
    {
        $this->assertCommand(
            sprintf('conversion:enable-ic %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
    }

    /**
     * @inheritdoc
     */
    protected function getProjects(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    protected function getLibraries(): array
    {
        return [];
    }
}
