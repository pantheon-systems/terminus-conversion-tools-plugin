<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsConvertToUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal8-non-composer custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsConvertToUpstreamTest extends ConversionCommandsUpstreamTestBase
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
        return '';
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
     * @group to_upstream
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);

        $this->executeConvertCommand();

        $composerJsonLocation = sprintf('~/pantheon-local-copies/%s_terminus_conversion_plugin/composer.json', $this->siteName);
        $composerJsonData = json_decode(file_get_contents($composerJsonLocation), true);

        $this->assertEquals(
            $composerJsonData['require']['drupal/core'],
            '8.9.19',
            'Drupal core version should be resolved to 8.9.19'
        );
    }

    /**
     * Executes the conversion Terminus command.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function executeConvertCommand(): void
    {
        $this->terminus(sprintf('conversion:convert-upstream-from-site %s --repo=%s --dry-run', $this->siteName, 'git@github.com:/foo/bar.git'));
    }
}
