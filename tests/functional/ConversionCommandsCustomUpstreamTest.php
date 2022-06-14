<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsCustomUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsCustomUpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_DROPS8_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getRealUpstreamId(): string
    {
        return getenv('TERMINUS_TEST_SITE_DROPS8_UPSTREAM_ID');
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'Advice: We recommend that this site be converted to a Composer-managed upstream';
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
     * @group custom_upstream
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
