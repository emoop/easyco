<?php

use Illuminate\Support\Facades\Route;

// /robots.txt through the app, so the EASYCO_NOINDEX switch can answer it. Where the web server serves public/robots.txt itself
// (Laragon / Apache) this route is never reached; the docker nginx hands the request to PHP on purpose (docker/nginx/default.conf.template).
// Switch off = the file's bytes, unchanged.
Route::get('/robots.txt', function () {
    $body = config('easyco.noindex')
        ? "User-agent: *\nDisallow: /\n"
        : (string) file_get_contents(public_path('robots.txt'));

    return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
});

require __DIR__.'/storefront.php';
