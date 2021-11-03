<?php

namespace Pantheon\TerminusConversionTools\Utils;

/**
 * Class Files.
 */
class Files
{
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
