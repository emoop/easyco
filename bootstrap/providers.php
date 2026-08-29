<?php

use App\Providers\AppServiceProvider;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use App\Providers\CatalogSlugGeneratorServiceProvider;
use App\Providers\SiteSettingsServiceProvider;

return [
    AppServiceProvider::class,
    CatalogSkuGeneratorServiceProvider::class,
    CatalogSlugGeneratorServiceProvider::class,
    SiteSettingsServiceProvider::class,
];
