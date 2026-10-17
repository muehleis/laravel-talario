<?php

require getenv('TALARIO_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Talario\\Laravel\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
