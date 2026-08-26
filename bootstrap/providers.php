<?php

use App\Providers\AppServiceProvider;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use App\Providers\CatalogSlugGeneratorServiceProvider;

return [
    AppServiceProvider::class,
    CatalogSkuGeneratorServiceProvider::class,
    CatalogSlugGeneratorServiceProvider::class,
];
