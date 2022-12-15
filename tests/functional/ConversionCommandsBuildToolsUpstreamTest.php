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
        return 'If you wish to preserve your Build Tools Workflow';
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
     * @group upstream_build_tools
     */
    public function testConversionCommands(): void
    {
        $this->assertPagesExists(self::DEV_ENV);
        $this->assertAdviseBeforeCommand();
        $this->executeConvertCommand();
    }

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        // Do nothing but setting up branch name.
        $this->branch = 'conversion';
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

    /**
     * Asserts the command executes as expected.
     *
     * @param string $command
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function assertCommand(string $command, string $env): void
    {
        $this->terminus($command);
        sleep(30);
        $this->terminus(sprintf('env:clear-cache %s.%s', $this->siteName, $env), [], false);
    }
}
