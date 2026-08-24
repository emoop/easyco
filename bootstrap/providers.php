<?php

use App\Providers\AppServiceProvider;
use App\Providers\CatalogSlugGeneratorServiceProvider;
use App\Providers\DemoHooksServiceProvider;

return [
    AppServiceProvider::class,
    DemoHooksServiceProvider::class,
    CatalogSlugGeneratorServiceProvider::class,
];
