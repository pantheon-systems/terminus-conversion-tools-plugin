<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsDrupalProjectUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-example-drops-8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsDrupalProjectUpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->setUpFixtureSite();
    }

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
        return 'drupal9';
    }

    /**
     * Returns the part of the advice copy before the conversion is executed.
     *
     * @return string
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'convert the site to use "drupal-recommended" Pantheon Upstream';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_drupal_project
     * @group advise_command
     */
    public function testConversionComposerCommands(): void
    {
        $adviceBefore = $this->terminus(sprintf('conversion:advise %s', $this->siteName));
        $this->assertTrue(
            false !== strpos($adviceBefore, $this->getExpectedAdviceBeforeConversion()),
            sprintf(
                'Advice for %s upstream-based site must contain "%s" copy. Actual advice is: "%s"',
                $this->getRealUpstreamId(),
                $this->getExpectedAdviceBeforeConversion(),
                $adviceBefore
            )
        );
    }
}
