<?php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'EasyCo\\Payment\\Tests\\' => __DIR__.'/',
        'EasyCo\\Payment\\' => __DIR__.'/../src/',
        'EasyCo\\Pricing\\' => __DIR__.'/../../Pricing/src/',
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
