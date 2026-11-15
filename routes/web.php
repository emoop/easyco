<?php

use Illuminate\Support\Facades\Route;

// /robots.txt is ALWAYS answered by the app (SEO-1): there is no public/robots.txt any more, so Apache (Laragon), nginx and Varnish all reach this route.
// The rules, the Sitemap line and the EASYCO_NOINDEX answer are App\Storefront\Seo\RobotsTxt.
Route::get('/robots.txt', fn () => response(\App\Storefront\Seo\RobotsTxt::body(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']));

require __DIR__.'/storefront.php';
