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
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return
            <<<EOD
convert the site to use "drupal-recommended" Pantheon Upstream by using `conversion:drupal-recommended`
EOD;
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
     * @group upstream_drupal_project
     * @group advise_command
     */
    public function testConversionComposerCommands(): void
    {
        $this->assertAdviseBeforeCommand();
    }
}
