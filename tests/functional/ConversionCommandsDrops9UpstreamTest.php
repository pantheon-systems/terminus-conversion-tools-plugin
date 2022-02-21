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
        return 'convert the site to a Composer managed one by using `conversion:composer` Terminus command';
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
     */
    protected function getProjects(): array
    {
        return [
            'webform',
            'metatag',
            'token',
            'entity',
            'imce',
            'field_group',
            'ctools',
            'pathauto',
            'google_analytics',
            'custom1',
            'custom2',
            'custom3',
        ];
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
