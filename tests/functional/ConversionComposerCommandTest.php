<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\Terminus\Tests\Traits\TerminusTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Class ConversionComposerCommandTest.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionComposerCommandTest extends TestCase
{
    use TerminusTestTrait;

    /**
     * @var string
     */
    private string $siteName;

    /**
     * @var string
     */
    private string $branch;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->siteName = $this->getSiteName();
        $this->branch = sprintf('test-%s', substr(uniqid(), -6, 6));
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->terminus(
            sprintf('multidev:delete %s.%s --delete-branch', $this->siteName, $this->branch)
        );
    }

    /**
     * @covers \Pantheon\TerminusConversionTools\Commands\ConvertToComposerSiteCommand
     *
     * @group convert_composer
     */
    public function testConversionComposerCommand():void
    {
        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }

        $this->terminus(
            sprintf('conversion:composer %s --branch=%s', $this->siteName, $this->branch)
        );
    }

    /**
     * Adds site's Git host to known_hosts file.
     */
    private function addGitHostToKnownHosts(): void
    {
        $gitInfo = $this->terminusJsonResponse(
            sprintf('connection:info %s.dev --fields=git_host,git_port', $this->siteName)
        );
        $this->assertIsArray($gitInfo);
        $this->assertNotEmpty($gitInfo);
        $this->assertArrayHasKey('git_host', $gitInfo);
        $this->assertArrayHasKey('git_port', $gitInfo);

        $addGitHostToKnownHostsCommand = sprintf(
            'ssh-keyscan -p %d %s 2>/dev/null >> ~/.ssh/known_hosts',
            $gitInfo['git_port'],
            $gitInfo['git_host']
        );
        exec($addGitHostToKnownHostsCommand);
    }
}
