<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsEmptyUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-example-drops-8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsEmptyUpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_EMPTY_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'Advice: We recommend that this site be converted to use "drupal-recommended" Pantheon upstream';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return 'No conversion is necessary.';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_empty
     * @group composer_command
     * @group release_to_master_command
     * @group restore_master_command
     * @group advise_command
     */
    public function testConversionCommands(): void
    {
        parent::testConversionCommands();
    }
}
