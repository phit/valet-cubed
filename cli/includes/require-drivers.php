<?php

// DEVELOPER NOTE: Do not use latest php's functions in this file, as this file works with isolated versions
require_once __DIR__ . '/../Valet/Drivers/ValetDriver.php';

$drivers = scandir(__DIR__ . '/../Valet/Drivers');
if ($drivers !== false) {
    foreach ($drivers as $file) {
        $path = __DIR__ . '/../Valet/Drivers/'.$file;
        if (substr($file, 0, 1) !== '.' && !is_dir($path)) {
            require_once $path;
        }
    }
}
