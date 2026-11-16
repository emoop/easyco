<?php

use Illuminate\Support\Facades\Route;

// /robots.txt is ALWAYS answered by the app (SEO-1; RobotsTxt in app/Storefront/Seo). P8b: it lives in routes/storefront.php, in the sessionless group (no cookie, cacheable).

require __DIR__.'/storefront.php';
