<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsDrupalProjectUpstreamTest.
 *
 * Uses a site fixture based on https://github.com/pantheon-fixtures/site-drupal9 custom upstream.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
final class ConversionCommandsDrupalProjectUpstreamTest extends ConversionCommandsUpstreamTestBase
{
    /**
     * @inheritdoc
     */
    protected function getUpstreamIdEnvName(): string
    {
        return 'TERMINUS_TEST_SITE_DRUPAL_PROJECT_UPSTREAM_ID';
    }

    /**
     * @inheritdoc
     */
    protected function getRealUpstreamId(): string
    {
        return 'drupal9';
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceBeforeConversion(): string
    {
        return
            <<<EOD
convert the site to use "drupal-recommended" Pantheon Upstream by using `conversion:drupal-recommended`
EOD;
    }

    /**
     * @inheritdoc
     */
    protected function getExpectedAdviceAfterConversion(): string
    {
        return 'Sorry, no advice is available.';
    }

    /**
     * @inheritdoc
     *
     * @group upstream_drupal_project
     * @group drupal_recommended_command
     * @group release_to_master_command
     * @group restore_master_command
     * @group advise_command
     */
    public function testConversionComposerCommands(): void
    {
        parent::testConversionComposerCommands();
    }

    /**
     * @inheritdoc
     */
    protected function executeConvertCommand(): void
    {
        $this->assertCommand(
            sprintf('conversion:drupal-recommended %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
    }

    /**
     * @inheritdoc
     */
    protected function getProjects(): array
    {
        return [
            'webform',
            'examples',
            'entity',
            'ctools',
            'custom1',
            'custom2',
            'custom3',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function assertLibrariesExists(string $env): void
    {
    }
}
