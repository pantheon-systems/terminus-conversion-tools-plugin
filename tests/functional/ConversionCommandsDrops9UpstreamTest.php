<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsDrops9UpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal9-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsDrops9UpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_DROPS9_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getRealUpstreamId(): string
    {
        return 'drupal8';
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
     * @group upstream_drops9
     */
    public function testConversionCommands(): void
    {
        parent::testConversionCommands();
    }
}
