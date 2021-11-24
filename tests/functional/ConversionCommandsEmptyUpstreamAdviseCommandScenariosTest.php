<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

use Pantheon\TerminusConversionTools\Utils\Files;

/**
 * Class ConversionCommandsEmptyUpstreamAdviseCommandScenariosTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal-recommended.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsEmptyUpstreamAdviseCommandScenariosTest extends ConversionCommandsUpstreamTestBase
{
    private const SCENARIO_ON_TOP_OF_DRUPAL_RECOMMENDED = 'on_top_of_drupal_recommended';
    private const SCENARIO_ON_TOP_OF_DRUPAL_PROJECT = 'on_top_of_drupal_project';

    /**
     * @var string
     */
    private string $scenario;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->setUpFixtureSite();
    }

    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_DRUPAL_RECOMMENDED_UPSTREAM_ID';
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
        switch ($this->scenario) {
            case self::SCENARIO_ON_TOP_OF_DRUPAL_RECOMMENDED:
                return 'switch the upstream to "drupal-recommended" with Terminus -';
            case self::SCENARIO_ON_TOP_OF_DRUPAL_PROJECT:
                return 'convert the site to use "drupal-recommended" Pantheon Upstream and then switch the upstream';
            default:
                return '';
        }
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
     * Test `conversion:advise` command for empty upstream in the following scenarios:
     *   1. empty upstream on top of drupal-recommended upstream;
     *   2. empty upstream on top of drupal-project upstream.
     *
     * @group advise_scenarios_empty_upstream
     * @group advise_command
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface
     */
    public function testConversionComposerCommands(): void
    {
        // Scenario #1: empty upstream on top of drupal-recommended.
        $this->scenario = self::SCENARIO_ON_TOP_OF_DRUPAL_RECOMMENDED;
        $this->assertAdviseBeforeCommand();

        // Scenario #2: empty upstream on top of drupal-project.
        $this->scenario = self::SCENARIO_ON_TOP_OF_DRUPAL_PROJECT;

        $composerJsonPath = Files::buildPath(
            getenv('HOME'),
            'pantheon-local-copies',
            $this->siteName . '_composer_conversion',
            'upstream-configuration',
            'composer.json'
        );
        $file = fopen($composerJsonPath, 'w');
        $composerJsonDrupalProject = $this
            ->httpClient
            ->request(
                'GET',
                <<<EOD
https://raw.githubusercontent.com/pantheon-upstreams/drupal-project/master/upstream-configuration/composer.json
EOD
            )
            ->getContent();
        fwrite($file, $composerJsonDrupalProject);
        fclose($file);

        $this->assertAdviseBeforeCommand();
    }
}
