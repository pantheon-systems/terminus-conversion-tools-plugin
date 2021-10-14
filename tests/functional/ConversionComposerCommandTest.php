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
     * @covers \Pantheon\TerminusConversionTools\Commands\ConvertToComposerSiteCommand
     *
     * @group convert_composer
     */
    public function testConversionComposerCommand()
    {
        [$output] = $this->terminus(
            sprintf('conversion:composer %s --branch=%s', $this->siteName, $this->branch)
        );
        fwrite(STDERR, print_r($output, true));

        // @todo: clear caches.
        // @todo: verify that the site is functional by pinging front page
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
}
