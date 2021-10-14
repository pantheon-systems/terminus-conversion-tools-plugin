<?php

namespace Pantheon\TerminusConversionTools\Utils;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class FileSystem.
 */
class FileSystem
{
    /**
     * Returns the list of files in the path matching the pattern.
     *
     * Limits the result to one file per path (directory).
     *
     * @param string $path
     * @param string $pattern
     *
     * @return array
     *  The list of files where the key is a path relative to the search path and the value is a file name (with an
     *  extension).
     */
    public static function getFilesByPattern(string $path, string $pattern)
    {
        if (!is_dir($path)) {
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator($path);
        $fileIterator = new RecursiveIteratorIterator($directoryIterator);
        $files = [];
        foreach (iterator_to_array($fileIterator) as $file) {
            if (isset($files[$file->getPath()])) {
                continue;
            }

            /** @var \SplFileInfo $file */
            if (!preg_match($pattern, $file->getRealPath())) {
                continue;
            }

            $files[$file->getPath()] = $file->getFilename();
        }

        return $files;
    }

    /**
     * Returns path to the file built from parts.
     *
     * @param string[] $parts
     *
     * @return string
     */
    public static function buildPath(...$parts): string
    {
        return implode(
            DIRECTORY_SEPARATOR,
            array_filter($parts, fn($part) => is_string($part))
        );
    }
}
