<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Symfony\Component\HttpClient\HttpClient;

/**
 * Class ConversionCommandsBuildToolsUpstreamTest.
 *
 * Uses https://github.com/pantheon-fixtures/convertbtfixture.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsBuildToolsUpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return '';
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
     * @group upstream_build_tools
     */
    public function testConversionCommands(): void
    {
        parent::testConversionCommands();
    }

    /**
     * @inheritdoc
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUp(): void
    {
        // Do nothing but setting up branch name.
        $this->branch = 'convertbt';
        $this->siteName = 'convertbtfixture';
        $this->httpClient = HttpClient::create();
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        // Do nothing.
    }

    /**
     * @inheritdoc
     */
    protected function getProjects(): array
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
     */
    protected function getUrlsToTestByModule(): array
    {
        return [];
    }
}
