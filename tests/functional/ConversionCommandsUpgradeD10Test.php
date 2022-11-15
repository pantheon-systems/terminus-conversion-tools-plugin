<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsUpgradeD10Test.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal-recommended-8 custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsUpgradeD10Test extends ConversionCommandsUpstreamTestBase
{

    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_UPGRADE_D10_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getRealUpstreamId(): string
    {
        return 'drupal-composer-managed';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return '';
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
            'token',
            'ctools',
            'pathauto',
            'google_analytics',
            'aggregator',
            'quick_edit',
            'rdf',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getUrlsToTestByModule(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    protected function getLibraries(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     *
     * @group upgrade_d10_command
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);

        $this->assertCommand(
            sprintf('conversion:upgrade-d10 --skip-upgrade-status %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:release-to-dev %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->terminus(sprintf('drush %s.dev -- cim -y', $this->siteName));

        $this->assertAdviseAfterCommand();
    }
}
