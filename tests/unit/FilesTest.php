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

    /**
     * @covers \Pantheon\TerminusConversionTools\Utils\Files::getFilesByPattern
     */
    public function testGetFilesByPattern()
    {
        $path = dirname(__DIR__, 2);

        $actual = Files::getFilesByPattern($path, '/(src|tests)(.*)(Files.php|FilesTest.php)$/');
        $expected = [
            Files::buildPath($path, 'src', 'Utils') => 'Files.php',
            Files::buildPath($path, 'tests', 'unit') => 'FilesTest.php',
        ];
        $this->assertEquals($expected, $actual);

        $actual = Files::getFilesByPattern($path, '/FileDoesNotExist(.*)\.php$/');
        $this->assertEquals([], $actual);

        $actual = Files::getFilesByPattern(
            Files::buildPath($path, 'directory', 'does', 'not', 'exist'),
            '/(.*)/'
        );
        $this->assertEquals([], $actual);
    }
}
