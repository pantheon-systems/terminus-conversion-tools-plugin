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
    private const SCENARIO_ON_TOP_OF_DRUPAL_TARGET = 'on_top_of_drupal_target';
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
    protected function getExpectedAdviceBeforeConversion(): string
    {
        switch ($this->scenario) {
            case self::SCENARIO_ON_TOP_OF_DRUPAL_TARGET:
                return 'switch the upstream to "drupal-composer-managed" with Terminus';
            case self::SCENARIO_ON_TOP_OF_DRUPAL_RECOMMENDED:
            case self::SCENARIO_ON_TOP_OF_DRUPAL_PROJECT:
                // phpcs:disable Generic.Files.LineLength.TooLong
                return 'Advice: We recommend that this site be converted to use "drupal-composer-managed" Pantheon upstream';
                // phpcs:enable Generic.Files.LineLength.TooLong
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
    public function testConversionCommands(): void
    {
        // Scenario #1: empty upstream on top of drupal-recommended.
        $this->scenario = self::SCENARIO_ON_TOP_OF_DRUPAL_RECOMMENDED;
        $this->assertAdviseBeforeCommand();

        // Scenario #2: empty upstream on top of drupal-project.
        $this->scenario = self::SCENARIO_ON_TOP_OF_DRUPAL_PROJECT;

        $composerJsonPath = Files::buildPath(
            getenv('HOME'),
            'pantheon-local-copies',
            $this->siteName . '_terminus_conversion_plugin',
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
