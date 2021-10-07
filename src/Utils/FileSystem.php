<?php

namespace Pantheon\TerminusConversionTools\Utils;

use Pantheon\Terminus\Helpers\Traits\CommandExecutorTrait;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class FileSystem.
 */
class FileSystem
{
    use CommandExecutorTrait;

    /**
     * Returns the list of files in the path matching the pattern.
     *
     * @param string $path
     * @param string $pattern
     *
     * @return array
     *  The list of files where the key is a path relative to the search path and the value is a file name (with an
     *  extension).
     */
    public function getFilesByPattern(string $path, string $pattern)
    {
        if (!is_dir($path)) {
            // @todo: throw exception?
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator($path);
        $fileIterator = new RecursiveIteratorIterator($directoryIterator);
        $files = [];
        foreach (iterator_to_array($fileIterator) as $file) {
            /** @var \SplFileInfo $file */
            if (!preg_match($pattern, $file->getRealPath())) {
                continue;
            }

            $files[$file->getPath()] = $file->getFilename();
        }

        return $files;
    }
}
