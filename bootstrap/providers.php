<?php

use App\Providers\AppServiceProvider;
use App\Providers\CatalogSkuGeneratorServiceProvider;
use App\Providers\CatalogSlugGeneratorServiceProvider;
use App\Providers\MediaControllerServiceProvider;
use App\Providers\SiteSettingsServiceProvider;

return [
    AppServiceProvider::class,
    CatalogSkuGeneratorServiceProvider::class,
    CatalogSlugGeneratorServiceProvider::class,
    MediaControllerServiceProvider::class,
    SiteSettingsServiceProvider::class,
];
