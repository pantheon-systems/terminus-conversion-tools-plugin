<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsCustomUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsCustomUpstreamTest extends ConversionCommandsUpstreamTestBase
{

    /**
     * Creates a fixture site and sets up upstream, and SSH keys on CI env.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUpFixtureSite(): void
    {
        $this->branch = sprintf('test-%s', substr(uniqid(), -6, 6));
        $this->httpClient = HttpClient::create();

        $this->siteName = uniqid(sprintf('fixture-term3-conv-plugin-drupal8-'));
        $command = sprintf(
            'site:create %s %s %s',
            $this->siteName,
            $this->siteName,
            $this->getUpstreamId()
        );
        $this->terminus(
            $command,
            [sprintf('--org=%s', $this->getOrg())]
        );

        $installCommand = sprintf('-y drush %s.%s -- site-install demo_umami -y', $this->siteName, self::DEV_ENV);
        $this->assertEqualsInAttempts(
            function () use ($installCommand) {
                [, $exitCode] = self::callTerminus($installCommand);
                return $exitCode;
            },
            0,
            sprintf('Failed installing fixture site (%s)', $installCommand)
        );

        $this->terminus(sprintf('connection:set %s.%s %s', $this->siteName, self::DEV_ENV, 'git'));

        $this->terminus(sprintf('site:upstream:set %s %s', $this->siteName, $this->getRealUpstreamId()));
        $this->expectedSiteInfoUpstream = $this->terminusJsonResponse(
            sprintf('site:info %s', $this->siteName)
        )['upstream'];
        sleep(15);

        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }
    }

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
        return getenv('TERMINUS_TEST_SITE_DROPS8_UPSTREAM_ID');
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
     * @group custom_upstream
     * @group composer_command
     * @group release_to_master_command
     * @group restore_master_command
     * @group advise_command
     */
    public function testConversionCommands(): void
    {
        parent::testConversionCommands();
    }
}
