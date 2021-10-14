<?php

use Pantheon\TerminusConversionTools\Utils\Files;

define('TERMINUS_BIN_FILE', Files::buildPath(getcwd(), '..', 'terminus'));
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
