<?php

namespace Pantheon\TerminusConversionTools\Tests\Unit;

use Pantheon\TerminusConversionTools\Utils\Files;
use PHPUnit\Framework\TestCase;

/**
 * FilesTest class.
 *
 * @covers \Pantheon\TerminusConversionTools\Utils\Files
 */
final class FilesTest extends TestCase
{
    /**
     * @covers \Pantheon\TerminusConversionTools\Utils\Files::buildPath
     */
    public function testBuildPath()
    {
        $actual = Files::buildPath('foo', true, false, ['array'], 124, 'bar', null, 0, 1);
        $this->assertEquals(sprintf('foo%sbar', DIRECTORY_SEPARATOR), $actual);

        $actual = Files::buildPath();
        $this->assertEquals('', $actual);
    }
}
