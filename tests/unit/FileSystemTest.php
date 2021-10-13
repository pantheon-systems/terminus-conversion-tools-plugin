<?php

namespace Pantheon\TerminusConversionTools\Tests;

use Pantheon\TerminusConversionTools\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

final class FileSystemTest extends TestCase
{
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
