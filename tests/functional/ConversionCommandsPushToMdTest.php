<?php

namespace Pantheon\TerminusConversionTools\Tests\Functional;

/**
 * Class ConversionCommandsPushToMdTest.
 *
 * @package Pantheon\TerminusConversionTools\Tests\Functional
 */
class ConversionCommandsPushToMdTest extends ConversionCommandsDrupalProjectUpstreamTest
{
    /**
     * @inheritdoc
     *
     * @group push_to_md_command
     */
    public function testConversionCommands(): void
    {
        parent::testConversionCommands();
    }

    /**
     * @inheritdoc
     */
    protected function executeConvertCommand(): void
    {
        $this->terminus(
            sprintf(
                'conversion:update-from-deprecated-upstream %s --branch=%s --dry-run',
                $this->siteName,
                $this->branch
            )
        );

        $this->assertCommand(
            sprintf('conversion:push-to-multidev %s --branch=%s', $this->siteName, $this->branch),
            $this->branch
        );
    }
}
