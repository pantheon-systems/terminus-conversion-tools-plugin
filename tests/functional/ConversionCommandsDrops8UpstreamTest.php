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
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return 'Advice: We recommend that this site be converted to a Composer-managed upstream';
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
     *
     * @group upstream_drops8
     * @group composer_command
     * @group release_to_master_command
     * @group restore_master_command
     * @group advise_command
     * @group upgrade_d9_command
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);

        $this->assertAdviseBeforeCommand();
        $this->executeConvertCommand();

        $this->assertCommand(
            sprintf('conversion:release-to-master %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals(
            '897fdf15-992e-4fa1-beab-89e2b5027e03: https://github.com/pantheon-upstreams/drupal-recommended',
            $siteInfoUpstream
        );
        $this->assertAdviseAfterCommand();

        // Delete multidev env so that we can cleanly continue with the D9 upgrade.
        $this->terminus(
            sprintf('multidev:delete %s.conversion --delete-branch', $this->siteName),
            ['--quiet'],
            false
        );

        $this->assertCommand(
            sprintf('conversion:upgrade-d9 --skip-upgrade-status %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:restore-master %s', $this->siteName),
            self::DEV_ENV
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals($this->expectedSiteInfoUpstream, $siteInfoUpstream);
    }
}
