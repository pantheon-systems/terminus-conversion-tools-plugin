<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsCreateProjectTest.s
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
     * Expected string to assert in the output.
     */
    protected $expectedOutput;

    /**
     * @inheritdoc
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUp(): void
    {
        $this->sitesBaseName = uniqid(sprintf('fixture-term3-conv-plugin-%s-', $this->getRealUpstreamId()));
        $distros = getenv('TERMINUS_TEST_DISTROS_OVERRIDE') ? getenv('TERMINUS_TEST_DISTROS_OVERRIDE') : getenv('TERMINUS_TEST_DISTROS_TO_TEST');
        $this->distros = explode(',', $distros);
        $this->expectedOutput = 'Your new project is ready at';
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
            $command = sprintf('conversion:create %s %s', $distro, $name);
        }
        $output = $this->terminus(
            $command,
            [sprintf('--org=%s', $this->getOrg())]
        );

        $this->assertStringContainsString($this->expectedOutput, $output, sprintf('Command %s probably failed. Expected output to contain %s', $command, $this->expectedOutput));
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        for ($index = 0; $index < count($this->distros); $index++) {
            $siteName = sprintf('%s-%s', $this->sitesBaseName, $index);
            $this->terminus(
                sprintf('site:delete %s', $siteName),
                ['--quiet'],
                false
            );
        }
    }
}
