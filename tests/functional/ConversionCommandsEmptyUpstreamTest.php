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
        return 'Sorry, no advice is available.';
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
}
