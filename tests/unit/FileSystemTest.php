<?php

namespace Pantheon\TerminusConversionTools\Tests;

use Pantheon\TerminusConversionTools\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

final class FileSystemTest extends TestCase
{
    public function testBuildPath()
    {
        $actual = FileSystem::buildPath('foo', true, false, ['array'], 124, 'bar', null, 0, 1);
        $this->assertEquals(sprintf('foo%sbar', DIRECTORY_SEPARATOR), $actual);

        $actual = FileSystem::buildPath();
        $this->assertEquals('', $actual);
    }

    public function testGetFilesByPattern()
    {
        $path = dirname(__DIR__, 2);

        $actual = FileSystem::getFilesByPattern($path, '/(src|tests)(.*)FileSystem(.*)\.php$/');
        $expected = [
            FileSystem::buildPath($path, 'src', 'Utils') => 'FileSystem.php',
            FileSystem::buildPath($path, 'tests', 'unit') => 'FileSystemTest.php',
        ];
        $this->assertEquals($expected, $actual);

        $actual = FileSystem::getFilesByPattern($path, '/FileDoesNotExist(.*)\.php$/');
        $this->assertEquals([], $actual);

        $actual = FileSystem::getFilesByPattern(
            FileSystem::buildPath($path, 'directory', 'does', 'not', 'exist'),
            '/(.*)/'
        );
        $this->assertEquals([], $actual);
    }
}
