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
    protected function getRealUpstreamId(): string
    {
        return 'empty';
    }

    /**
     * Returns the part of the advice copy before the conversion is executed.
     *
     * @return string
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'convert the site to support Pantheon Integrated Composer';
    }

    /**
     * Returns the part of the advice copy after the conversion is executed.
     *
     * @return string
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        // @todo: update with 'Sorry, no advice is available.' once
        // "[CMS-406] Use "drupal-recommended" target upstream in conversion:composer
        // and conversion:release-to-master commands" has merged.
        return 'convert the site to use "drupal-recommended" Pantheon Upstream';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_empty
     */
    public function testConversionComposerCommands(): void
    {
        parent::testConversionComposerCommands();
    }
}
