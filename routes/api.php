<?php

use App\Http\Controllers\Api\AttributeDefinitionController;
use App\Http\Controllers\Api\AttributeValueController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\VariableProductController;
use Illuminate\Support\Facades\Route;

Route::post('/products', [ProductController::class, 'store']);
Route::post('/products/variable', [VariableProductController::class, 'store']);

Route::post('/media', [MediaController::class, 'store']);

Route::post('/attribute-definitions', [AttributeDefinitionController::class, 'store']);
Route::get('/attribute-definitions', [AttributeDefinitionController::class, 'index']);
Route::get('/attribute-definitions/{id}/values', [AttributeValueController::class, 'index']);

Route::post('/attribute-values', [AttributeValueController::class, 'store']);
