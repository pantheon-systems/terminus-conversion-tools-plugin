<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsUpgradeD9Test.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsUpgradeD9Test extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_UPGRADE_D9_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getRealUpstreamId(): string
    {
        return 'drupal-recommended';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'No conversion is necessary.';
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
            'admin_toolbar',
            'token',
            'ctools',
            'pathauto',
            'google_analytics',
        ];
    }

    /**
     * @inheritdoc
     *
     * @group upgrade_d9_command
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);

        $this->assertAdviseBeforeCommand();

        $this->assertCommand(
            sprintf('conversion:upgrade-d9 --skip-upgrade-status %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:release-to-master %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertAdviseAfterCommand();

        $this->assertCommand(
            sprintf('conversion:restore-master %s', $this->siteName),
            self::DEV_ENV
        );
    }
}
