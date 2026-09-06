<?php

require __DIR__ . '/../vendor/autoload.php';

// The module guards its entry-point file with this constant; the library
// classes under test do not need WHMCS itself.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}
