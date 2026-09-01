<?php

use App\Http\Controllers\Api\AccountRegistrationController;
use App\Http\Controllers\Api\AccountSessionController;
use App\Http\Controllers\Api\AttributeDefinitionController;
use App\Http\Controllers\Api\AttributeValueController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductMediaController;
use App\Http\Controllers\Api\StockLevelController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\VariableProductController;
use App\Http\Controllers\Api\VariationMediaController;
use Illuminate\Support\Facades\Route;

Route::post('/account/register', [AccountRegistrationController::class, 'store']);
Route::post('/account/login', [AccountSessionController::class, 'store'])
    ->middleware('throttle:6,1');

Route::middleware('auth:customer')->group(function () {
    Route::post('/account/logout', [AccountSessionController::class, 'destroy']);
    Route::get('/account/me', [AccountSessionController::class, 'show']);
});

Route::post('/products', [ProductController::class, 'store']);
Route::post('/products/variable', [VariableProductController::class, 'store']);
Route::put('/products/{productId}/brand', [ProductController::class, 'updateBrand']);
Route::post('/products/{productId}/media', [ProductMediaController::class, 'store']);
Route::get('/products/{productId}/media', [ProductMediaController::class, 'index']);
Route::put('/products/{productId}/media/order', [ProductMediaController::class, 'reorder']);
Route::delete('/products/{productId}/media/{productMediaId}', [ProductMediaController::class, 'destroy']);

Route::post('/variations/{variationId}/media', [VariationMediaController::class, 'store']);
Route::get('/variations/{variationId}/media', [VariationMediaController::class, 'index']);
Route::put('/variations/{variationId}/media/order', [VariationMediaController::class, 'reorder']);
Route::delete('/variations/{variationId}/media/{variationMediaId}', [VariationMediaController::class, 'destroy']);

Route::get('/variations/{variationId}/stock', [StockLevelController::class, 'show']);
Route::put('/variations/{variationId}/stock', [StockLevelController::class, 'update']);

Route::get('/cart', [CartController::class, 'index']);
Route::post('/cart/lines', [CartController::class, 'store']);
Route::patch('/cart/lines/{variationId}', [CartController::class, 'update']);
Route::delete('/cart/lines/{variationId}', [CartController::class, 'destroy']);

Route::post('/media', [MediaController::class, 'store']);

Route::post('/attribute-definitions', [AttributeDefinitionController::class, 'store']);
Route::get('/attribute-definitions', [AttributeDefinitionController::class, 'index']);
Route::get('/attribute-definitions/{id}/values', [AttributeValueController::class, 'index']);

Route::post('/attribute-values', [AttributeValueController::class, 'store']);

Route::post('/brands', [BrandController::class, 'store']);
Route::get('/brands', [BrandController::class, 'index']);

Route::post('/categories', [CategoryController::class, 'store']);
Route::get('/categories', [CategoryController::class, 'index']);

Route::post('/tags', [TagController::class, 'store']);
Route::get('/tags', [TagController::class, 'index']);
