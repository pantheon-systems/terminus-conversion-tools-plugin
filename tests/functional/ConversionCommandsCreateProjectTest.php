<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsCreateProjectTest.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsCreateProjectTest extends ConversionCommandsTestBase
{

    /**
     * Distros to test.
     */
    protected array $distros;

    /**
     * Sites base name.
     */
    protected string $sitesBaseName;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->httpClient = HttpClient::create();
        $this->sitesBaseName = uniqid(sprintf('fixture-term3-conv-plugin-%s-', $this->getRealUpstreamId()));
        $distros = getenv('TERMINUS_TEST_DISTROS_OVERRIDE') ?: getenv('TERMINUS_TEST_DISTROS_TO_TEST');
        $this->distros = explode(',', $distros);
    }

    /**
     * @inheritdoc
     *
     * @group from_distro
     */
    public function testConversionCommands(): void
    {
        foreach ($this->distros as $index => $distro) {
            $siteName = sprintf('%s-%s', $this->sitesBaseName, $index);
            $command = sprintf('conversion:create %s %s', $distro, $siteName);
            $this->terminus(
                $command,
                [sprintf('--org=%s', $this->getOrg())]
            );
            $url = sprintf('https://dev-%s.pantheonsite.io/install.php', $siteName);
            $this->assertEqualsInAttempts(
                fn() => $this->httpClient->request('HEAD', $url)->getStatusCode(),
                200,
                sprintf(
                    'Install page "%s" must return HTTP status code 200',
                    $url
                )
            );
        }
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        foreach ($this->distros as $index => $distro) {
            $siteName = sprintf('%s-%s', $this->sitesBaseName, $index);
            $this->terminus(
                sprintf('site:delete %s', $siteName),
                ['--quiet'],
                false
            );
        }
    }
}
