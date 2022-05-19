<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsUpstreamTestBase.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
abstract class ConversionCommandsUpstreamTestBase extends ConversionCommandsTestBase
{
    /**
     * Returns the part of the advice copy before the conversion is executed.
     *
     * @return string
     */
    abstract protected function getExpectedAdviceBeforeConversion(): string;

    /**
     * Returns the part of the advice copy after the conversion is executed.
     *
     * @return string
     */
    abstract protected function getExpectedAdviceAfterConversion(): string;

    /**
     * @covers \Pantheon\TerminusConversionTools\Commands\ConvertToComposerSiteCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\ReleaseToMasterCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\RestoreMasterCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\AdviseCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\PushToMultidevCommand
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);

        $this->assertAdviseBeforeCommand();
        $this->executeConvertCommand();

        $this->assertCommand(
            sprintf('conversion:release-to-dev %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals(
            'bde48795-b16d-443f-af01-8b1790caa1af: https://github.com/pantheon-upstreams/drupal-composer-managed.git',
            $siteInfoUpstream
        );
        $this->assertAdviseAfterCommand();

        $this->assertCommand(
            sprintf('conversion:restore-dev %s', $this->siteName),
            self::DEV_ENV
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals($this->expectedSiteInfoUpstream, $siteInfoUpstream);
    }

    /**
     * Asserts the `conversion:advise` command before the conversion is executed.
     */
    protected function assertAdviseBeforeCommand(): void
    {
        $adviceBefore = $this->terminus(sprintf('conversion:advise %s', $this->siteName));
        $this->assertTrue(
            false !== strpos($adviceBefore, $this->getExpectedAdviceBeforeConversion()),
            sprintf(
                'Advice for %s upstream-based site must contain "%s" copy. Actual advice is: "%s"',
                $this->getRealUpstreamId(),
                $this->getExpectedAdviceBeforeConversion(),
                $adviceBefore
            )
        );
    }

    /**
     * Asserts the `conversion:advise` command after the conversion is executed.
     */
    protected function assertAdviseAfterCommand(): void
    {
        $adviceAfter = $this->terminus(sprintf('conversion:advise %s', $this->siteName));
        $this->assertTrue(
            false !== strpos($adviceAfter, $this->getExpectedAdviceAfterConversion()),
            sprintf(
                'Advice must contain "%s" copy. Actual advice is: "%s"',
                $this->getExpectedAdviceAfterConversion(),
                $adviceAfter
            )
        );
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
            'date',
            'pathauto',
            'google_analytics',
            'adminimal_theme',
            'bootstrap',
            'omega',
            'custom1',
            'custom2',
            'custom3',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getLibraries(): array
    {
        return [
            'blazy' => 'blazy.js',
            'font' => 'plugin.js',
            'rtseo.js' => 'dist/rtseo.js',
            'superfish' => 'superfish.js',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getUrlsToTestByModule(): array
    {
        return [
            'webform' => 'form/contact',
            'custom1' => 'custom1/page',
            'custom2' => 'custom2/page',
            'custom3' => 'custom3/page',
        ];
    }
}
