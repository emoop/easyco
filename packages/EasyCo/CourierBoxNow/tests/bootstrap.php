<?php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'EasyCo\\CourierBoxNow\\Tests\\' => __DIR__.'/',
        'EasyCo\\CourierBoxNow\\' => __DIR__.'/../src/',
        'EasyCo\\Shipping\\' => __DIR__.'/../../Shipping/src/',
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
