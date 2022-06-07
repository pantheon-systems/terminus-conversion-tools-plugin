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
        return 'you might want to convert to drupal-composer-managed if you are NOT using Continuous Integration';
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
        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }
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

    /**
     * Adds site's Git host to known_hosts file.
     */
    protected function addGitHostToKnownHosts(): void
    {
        parent::addGitHostToKnownHosts();

        $addGitHostToKnownHostsCommand = sprintf(
            'ssh-keyscan -p %d %s 2>/dev/null >> ~/.ssh/known_hosts',
            '22',
            'github.com'
        );
        exec($addGitHostToKnownHostsCommand);
    }
}