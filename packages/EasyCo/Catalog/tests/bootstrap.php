<?php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'EasyCo\\Catalog\\Tests\\' => __DIR__.'/',
        'EasyCo\\Catalog\\' => __DIR__.'/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $path = $baseDir.str_replace('\\', '/', $relative).'.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    }
});
