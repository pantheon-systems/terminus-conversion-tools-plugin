<?php

use Pantheon\TerminusConversionTools\Utils\Files;

include_once 'vendor/autoload.php';

define('TERMINUS_BIN_FILE', Files::buildPath(getcwd(), '..', 'terminus'));
exec(
    sprintf(
        '%s auth:login --machine-token=%s',
        TERMINUS_BIN_FILE,
        getenv('TERMINUS_TOKEN')
    )
);
