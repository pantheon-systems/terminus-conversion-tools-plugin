<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsDrops8UpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsDrops8UpstreamTest extends ConversionCommandsUpstreamTestBase
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
        return 'drupal8';
    }

    /**
     * Returns the part of the advice copy before the conversion is executed.
     *
     * @return string
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'convert the site to using `conversion:composer` Terminus command';
    }

    /**
     * Returns the part of the advice copy after the conversion is executed.
     *
     * @return string
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return 'Sorry, no advice is available.';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_drops8
     */
    public function testConversionComposerCommands(): void
    {
        parent::testConversionComposerCommands();
    }
}
