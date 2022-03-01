<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Tests\Traits\TerminusTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class ConversionCommandsTestBase.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
abstract class ConversionCommandsTestBase extends TestCase
{
    use TerminusTestTrait;

    protected const DEV_ENV = 'dev';

    /**
     * @var string
     */
    protected string $siteName;

    /**
     * @var string
     */
    protected string $branch;

    /**
     * @var \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    protected HttpClientInterface $httpClient;

    /**
     * @var string
     */
    protected string $expectedSiteInfoUpstream;

    /**
     * Returns env variable name for the fixture Upstream ID.
     *
     * @return string
     */
    protected function getUpstreamIdEnvName(): string
    {
        return '';
    }

    /**
     * Returns the initial and expected (real) upstream ID of a fixture site.
     *
     * @return string
     */
    protected function getRealUpstreamId(): string
    {
        return 'empty';
    }

    /**
     * @inheritdoc
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUp(): void
    {
        $this->setUpFixtureSite();
        $this->setUpProjects();
    }

    /**
     * Creates a fixture site and sets up upstream, and SSH keys on CI env.
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    protected function setUpFixtureSite(): void
    {
        $this->branch = sprintf('test-%s', substr(uniqid(), -6, 6));
        $this->httpClient = HttpClient::create();

        $this->siteName = uniqid(sprintf('fixture-term3-conv-plugin-%s-', $this->getRealUpstreamId()));
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
        $this->terminus(
            sprintf('drush %s.%s -- site-install demo_umami', $this->siteName, self::DEV_ENV),
            ['-y']
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
    protected function tearDown(): void
    {
        $this->terminus(
            sprintf('site:delete %s', $this->siteName),
            ['--quiet'],
            false
        );
    }

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

        $this->assertCommand(
            sprintf('conversion:restore-master %s', $this->siteName),
            self::DEV_ENV
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals($this->expectedSiteInfoUpstream, $siteInfoUpstream);
    }

    /**
     * Executes the conversion Terminus command.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function executeConvertCommand(): void
    {
        $this->assertCommand(
            sprintf('conversion:composer %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
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

        $drushCrCommand = sprintf('drush %s.%s -- cache-rebuild', $this->siteName, $env);
        $this->assertEqualsInAttempts(
            fn() => static::callTerminus($drushCrCommand)[1],
            0,
            sprintf('Execution of `%s` has failed', $drushCrCommand)
        );

        $this->assertPagesExists($env);
        $this->assertLibrariesExists($env);
    }

    /**
     * Returns the list of contrib and custom projects to install.
     *
     * @return string[]
     */
    protected function getProjects(): array
    {
        return [];
    }

    /**
     * Returns the list of contrib JavaScript libraries to test.
     *
     * @return string[]
     */
    protected function getLibraries(): array
    {
        return [];
    }

    /**
     * Returns page URLs to test.
     *
     * @return array
     *   Key is a module name, value is a page absolute URL.
     */
    protected function getUrlsToTestByModule(): array
    {
        return [];
    }

    /**
     * Sets up (installs) projects (modules and themes).
     */
    private function setUpProjects(): void
    {
        foreach ($this->getProjects() as $name) {
            $command = sprintf('drush %s.%s -y -- en %s', $this->siteName, self::DEV_ENV, $name);
            $this->assertEqualsInAttempts(
                function () use ($command) {
                    [, $exitCode] = self::callTerminus($command);
                    return $exitCode;
                },
                0,
                sprintf('Failed enabling drupal project "%s" (%s)', $name, $command)
            );
        }
    }

    /**
     * Asserts pages returns HTTP Status 200 for a set of predefined URLs.
     *
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    protected function assertPagesExists(string $env): void
    {
        $baseUrl = $this->getBaseTestUrl($env);
        $this->assertEqualsInAttempts(
            fn() => $this->httpClient->request('HEAD', $baseUrl)->getStatusCode(),
            200,
            sprintf(
                'Front page "%s" must return HTTP status code 200',
                $baseUrl
            )
        );

        foreach ($this->getUrlsToTestByModule() as $module => $path) {
            $url = sprintf('%s/%s', $baseUrl, $path);
            $this->assertEqualsInAttempts(
                fn() => $this->httpClient->request('HEAD', $url)->getStatusCode(),
                200,
                sprintf('Module "%s" must provide page by path "%s" (%s)', $module, $path, $url)
            );
        }
    }

    /**
     * Returns the fixture site URL.
     *
     * @param string $env
     *
     * @return string
     */
    protected function getBaseTestUrl(string $env): string
    {
        return  $baseUrl = sprintf('https://%s-%s.pantheonsite.io', $env, $this->siteName);
    }

    /**
     * Asserts test JavaScript libraries' scripts exist.
     *
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function assertLibrariesExists(string $env): void
    {
        $baseUrl = sprintf('https://%s-%s.pantheonsite.io', $env, $this->siteName);
        foreach ($this->getLibraries() as $directory => $file) {
            $url = sprintf('%s/libraries/%s/%s', $baseUrl, $directory, $file);
            $this->assertEquals(
                200,
                $this->httpClient->request('HEAD', $url)->getStatusCode(),
                sprintf('Library "%s" must have script file "%s"', $directory, $url)
            );
        }
    }

    /**
     * Returns the fixture Upstream ID.
     *
     * @return string
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    private function getUpstreamId(): string
    {
        if (!getenv($this->getUpstreamIdEnvName())) {
            throw new TerminusException(sprintf('Missing "%s" env var', $this->getUpstreamIdEnvName()));
        }

        return getenv($this->getUpstreamIdEnvName());
    }

    /**
     * Adds site's Git host to known_hosts file.
     */
    protected function addGitHostToKnownHosts(): void
    {
        $gitInfo = $this->terminusJsonResponse(
            sprintf('connection:info %s.%s --fields=git_host,git_port', $this->siteName, self::DEV_ENV)
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

    /**
     * Asserts the actual result is equal to the expected one in multiple attempts.
     *
     * @param callable $callable
     *   Callable which provides the actual result.
     * @param mixed $expected
     *   Expected result.
     * @param string $message
     *   Message.
     */
    protected function assertEqualsInAttempts(
        callable $callable,
        $expected,
        string $message = ''
    ): void {
        $attempts = 18;
        $intervalSeconds = 10;

        do {
            $actual = $callable();
            if ($actual === $expected) {
                break;
            }

            sleep($intervalSeconds);
            $attempts--;
        } while ($attempts > 0);

        $this->assertEquals($expected, $actual, $message);
    }
}
