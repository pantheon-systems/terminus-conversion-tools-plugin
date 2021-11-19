<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsEmptyDrupalRecommendedUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal-recommended.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsEmptyDrupalRecommendedUpstreamTest extends ConversionCommandsUpstreamTestBase
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
        return 'TERMINUS_TEST_SITE_DRUPAL_RECOMMENDED_UPSTREAM_ID';
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
        return 'switch the upstream to "drupal-recommended" with Terminus -';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return '';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_empty_drupal_recommended
     * @group advise_command
     */
    public function testConversionComposerCommands(): void
    {
        $this->assertAdviseBeforeCommand();
    }
}
