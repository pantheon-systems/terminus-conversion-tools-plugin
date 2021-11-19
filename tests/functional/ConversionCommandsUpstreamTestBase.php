<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Tests\Traits\TerminusTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class ConversionCommandsUpstreamTestBase.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
abstract class ConversionCommandsUpstreamTestBase extends TestCase
{
    use TerminusTestTrait;

    private const DEV_ENV = 'dev';

    /**
     * @var string
     */
    protected string $siteName;

    /**
     * @var string
     */
    private string $branch;

    /**
     * @var \Symfony\Contracts\HttpClient\HttpClientInterface
     */
    private HttpClientInterface $httpClient;

    /**
     * @var string
     */
    private string $expectedSiteInfoUpstream;

    /**
     * Returns env variable name for the fixture Upstream ID.
     *
     * @return string
     */
    abstract protected function getUpstreamIdEnvName(): string;

    /**
     * Returns the initial and expected (real) upstream ID of a fixture site.
     *
     * @return string
     */
    abstract protected function getRealUpstreamId(): string;

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
            sprintf('drush %s.dev -- site-install demo_umami', $this->siteName),
            ['-y']
        );

        $this->terminus(sprintf('connection:set %s.dev %s', $this->siteName, 'git'));

        $this->terminus(sprintf('site:upstream:set %s %s', $this->siteName, $this->getRealUpstreamId()));
        $this->expectedSiteInfoUpstream = $this->terminusJsonResponse(
            sprintf('site:info %s', $this->siteName)
        )['upstream'];

        if ($this->isCiEnv()) {
            $this->addGitHostToKnownHosts();
        }
    }

    /**
     * Sets up (installs) projects (modules and themes).
     */
    protected function setUpProjects(): void
    {
        $contribProjects = [
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
        ];
        $customProjects = [
            'custom1',
            'custom2',
            'custom3',
        ];
        foreach (array_merge($contribProjects, $customProjects) as $name) {
            $this->terminus(
                sprintf('drush %s.dev -- en %s', $this->siteName, $name),
                ['-y']
            );
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
     * @covers \Pantheon\TerminusConversionTools\Commands\ReleaseComposerifyToMasterCommand
     * @covers \Pantheon\TerminusConversionTools\Commands\RestoreMasterCommand
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function testConversionComposerCommands(): void
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

        $this->assertCommand(
            sprintf('conversion:composer %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );

        $this->assertCommand(
            sprintf('conversion:release-to-master %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals(
            '897fdf15-992e-4fa1-beab-89e2b5027e03: https://github.com/pantheon-upstreams/drupal-recommended',
            $siteInfoUpstream
        );

        $adviceAfter = $this->terminus(sprintf('conversion:advise %s', $this->siteName));
        $this->assertTrue(
            false !== strpos($adviceAfter, $this->getExpectedAdviceAfterConversion()),
            sprintf(
                'Advice must contain "%s" copy. Actual advice is: "%s"',
                $this->getExpectedAdviceAfterConversion(),
                $adviceAfter
            )
        );

        $this->assertCommand(
            sprintf('conversion:restore-master %s', $this->siteName),
            self::DEV_ENV
        );
        $siteInfoUpstream = $this->terminusJsonResponse(sprintf('site:info %s', $this->siteName))['upstream'];
        $this->assertEquals($this->expectedSiteInfoUpstream, $siteInfoUpstream);
    }

    /**
     * Asserts the command executes as expected.
     *
     * @param string $command
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function assertCommand(string $command, string $env): void
    {
        $this->terminus($command);
        sleep(60);
        $this->terminus(sprintf('env:clear-cache %s.%s', $this->siteName, $env), [], false);
        $this->assertPagesExists($env);
        $this->assertLibrariesExists($env);
    }

    /**
     * Asserts pages returns HTTP Status 200 for a set of predefined URLs.
     *
     * @param string $env
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function assertPagesExists(string $env): void
    {
        $baseUrl = sprintf('https://%s-%s.pantheonsite.io', $env, $this->siteName);
        $this->assertEqualsInAttempts(
            fn () => $this->httpClient->request('HEAD', $baseUrl)->getStatusCode(),
            200,
            sprintf(
                'Front page "%s" must return HTTP status code 200',
                $baseUrl
            )
        );

        $pathsToTest = [
            'webform' => 'form/contact',
            'custom1' => 'custom1/page',
            'custom2' => 'custom2/page',
            'custom3' => 'custom3/page',
        ];
        foreach ($pathsToTest as $module => $path) {
            $url = sprintf('%s/%s', $baseUrl, $path);
            $this->assertEquals(
                200,
                $this->httpClient->request('HEAD', $url)->getStatusCode(),
                sprintf('Module "%s" must provide page by path "%s" (%s)', $module, $path, $url)
            );
        }
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
        $libraries = [
            'blazy' => 'blazy.js',
            'font' => 'plugin.js',
            'rtseo.js' => 'dist/rtseo.js',
            'superfish' => 'superfish.js',
        ];

        foreach ($libraries as $directory => $file) {
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
    private function assertEqualsInAttempts(
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
