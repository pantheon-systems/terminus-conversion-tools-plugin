<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsEnableIntegratedComposerTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-non-ic custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionCommandsEnableIntegratedComposerTest extends ConversionCommandsTestBase
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
     *
     * @group enable_integrated_composer_command
     */
    public function testConversionCommands(): void
    {
        $this->assertCommand(
            sprintf('conversion:enable-ic %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        // @todo: run command again and expect to receive "[error]  Pantheon Integrated Composer feature is already enabled on the site non-ic-site."
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

    /**
     * @inheritdoc
     */
    protected function getUrlsToTestByModule(): array
    {
        return [];
    }
}
