<?php

use App\Http\Controllers\Api\AttributeDefinitionController;
use App\Http\Controllers\Api\AttributeValueController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductMediaController;
use App\Http\Controllers\Api\VariableProductController;
use App\Http\Controllers\Api\VariationMediaController;
use Illuminate\Support\Facades\Route;

Route::post('/products', [ProductController::class, 'store']);
Route::post('/products/variable', [VariableProductController::class, 'store']);
Route::post('/products/{productId}/media', [ProductMediaController::class, 'store']);
Route::get('/products/{productId}/media', [ProductMediaController::class, 'index']);
Route::put('/products/{productId}/media/order', [ProductMediaController::class, 'reorder']);
Route::delete('/products/{productId}/media/{productMediaId}', [ProductMediaController::class, 'destroy']);

Route::post('/variations/{variationId}/media', [VariationMediaController::class, 'store']);
Route::get('/variations/{variationId}/media', [VariationMediaController::class, 'index']);
Route::put('/variations/{variationId}/media/order', [VariationMediaController::class, 'reorder']);
Route::delete('/variations/{variationId}/media/{variationMediaId}', [VariationMediaController::class, 'destroy']);

Route::post('/media', [MediaController::class, 'store']);

Route::post('/attribute-definitions', [AttributeDefinitionController::class, 'store']);
Route::get('/attribute-definitions', [AttributeDefinitionController::class, 'index']);
Route::get('/attribute-definitions/{id}/values', [AttributeValueController::class, 'index']);

Route::post('/attribute-values', [AttributeValueController::class, 'store']);
