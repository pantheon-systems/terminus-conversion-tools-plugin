<?php

use Pantheon\TerminusConversionTools\Utils\Files;

if (getenv('TERMINUS_BIN_PATH')) {
    // Local development setup.
    define('TERMINUS_BIN_FILE', getenv('TERMINUS_BIN_PATH'));
} else {
    // GitHub actions workflow setup.
    define('TERMINUS_BIN_FILE', Files::buildPath(getcwd(), '..', 'terminus.phar'));
}

if (!is_file(TERMINUS_BIN_FILE)) {
    return;
}

exec(
    sprintf(
        '%s auth:login --machine-token=%s',
        TERMINUS_BIN_FILE,
        getenv('TERMINUS_TOKEN')
    )
);
