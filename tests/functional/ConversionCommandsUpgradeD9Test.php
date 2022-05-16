<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsUpgradeD9Test.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal-recommended-8 custom upstream.
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
        return 'empty';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return 'We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream';
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
     */
    protected function getUrlsToTestByModule(): array
    {
        return [
            'webform' => 'form/contact',
        ];
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
            sprintf('conversion:release-to-dev %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertAdviseAfterCommand();
    }
}
