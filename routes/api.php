<?php

use App\Http\Controllers\Api\AccountRegistrationController;
use App\Http\Controllers\Api\AccountSessionController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AttributeDefinitionController;
use App\Http\Controllers\Api\AttributeValueController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductMediaController;
use App\Http\Controllers\Api\ProductTagController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\PromotionScopeController;
use App\Http\Controllers\Api\StockLevelController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\VariableProductController;
use App\Http\Controllers\Api\VariationController;
use App\Http\Controllers\Api\VariationMediaController;
use Illuminate\Support\Facades\Route;

Route::post('/account/register', [AccountRegistrationController::class, 'store']);
Route::post('/account/login', [AccountSessionController::class, 'store'])
    ->middleware('throttle:6,1');

Route::middleware('auth:customer')->group(function () {
    Route::post('/account/logout', [AccountSessionController::class, 'destroy']);
    Route::get('/account/me', [AccountSessionController::class, 'show']);
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::put('/addresses/{addressId}', [AddressController::class, 'update']);
});

Route::post('/addresses', [AddressController::class, 'store']);

Route::get('/cart', [CartController::class, 'index']);
Route::post('/cart/lines', [CartController::class, 'store']);
Route::patch('/cart/lines/{variationId}', [CartController::class, 'update']);
Route::delete('/cart/lines/{variationId}', [CartController::class, 'destroy']);
Route::put('/cart/promotion', [CartController::class, 'applyPromotion']);
Route::delete('/cart/promotion', [CartController::class, 'removePromotion']);

// Checkout is available to guests AND logged-in customers, so it must
// NOT go inside the auth:customer group above.
Route::post('/checkout', [CheckoutController::class, 'store']);

// ─────────────────────────────────────────────────────────────────
// Merchant surface — everything below requires auth:staff plus a
// declared staff.can:* permission on every route. See
// staff-access-domain-design.md §1/§5.
// tests/Feature/MerchantRoutesRequirePermissionTest.php audits this
// at the route-table level: a route added below without both
// requirements fails that test — deliberately, per §5 rule 1.
// ─────────────────────────────────────────────────────────────────
Route::middleware('auth:staff')->group(function () {
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::post('/products/variable', [VariableProductController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::put('/products/{productId}/brand', [ProductController::class, 'updateBrand'])
        ->middleware('staff.can:product_manage');
    Route::post('/products/{productId}/media', [ProductMediaController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::get('/products/{productId}/media', [ProductMediaController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::put('/products/{productId}/media/order', [ProductMediaController::class, 'reorder'])
        ->middleware('staff.can:product_manage');
    Route::delete('/products/{productId}/media/{productMediaId}', [ProductMediaController::class, 'destroy'])
        ->middleware('staff.can:product_manage');

    Route::post('/products/{productId}/categories', [ProductCategoryController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::get('/products/{productId}/categories', [ProductCategoryController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::delete('/products/{productId}/categories/{categoryId}', [ProductCategoryController::class, 'destroy'])
        ->middleware('staff.can:product_manage');

    Route::post('/products/{productId}/tags', [ProductTagController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::get('/products/{productId}/tags', [ProductTagController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::delete('/products/{productId}/tags/{tagId}', [ProductTagController::class, 'destroy'])
        ->middleware('staff.can:product_manage');

    Route::post('/variations/{variationId}/media', [VariationMediaController::class, 'store'])
        ->middleware('staff.can:product_manage');
    Route::get('/variations/{variationId}/media', [VariationMediaController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::put('/variations/{variationId}/media/order', [VariationMediaController::class, 'reorder'])
        ->middleware('staff.can:product_manage');
    Route::delete('/variations/{variationId}/media/{variationMediaId}', [VariationMediaController::class, 'destroy'])
        ->middleware('staff.can:product_manage');

    // No dedicated stock/inventory permission exists in the current
    // Permission vocabulary (§3) — stock is treated as part of product
    // management until/unless a dedicated permission is introduced.
    // Flagged for the domain owner; not this task's call to add one.
    Route::get('/variations/{variationId}/stock', [StockLevelController::class, 'show'])
        ->middleware('staff.can:product_view');
    Route::put('/variations/{variationId}/stock', [StockLevelController::class, 'update'])
        ->middleware('staff.can:product_manage');

    // POST .../restore (a state transition on a sub-resource), matching
    // this file's own nested-action precedent (POST .../media,
    // PUT .../stock) — deliberately NOT a PATCH /variations/{id} with a
    // status field, which would open a GENERAL status mutator with its
    // own guardrails and its own review; not this task. Gated by
    // product_manage since it's a write — see
    // MerchantRoutesRequirePermissionTest, which audits this
    // automatically.
    Route::post('/variations/{variationId}/restore', [VariationController::class, 'restore'])
        ->middleware('staff.can:product_manage');

    Route::post('/media', [MediaController::class, 'store'])
        ->middleware('staff.can:product_manage');

    Route::post('/attribute-definitions', [AttributeDefinitionController::class, 'store'])
        ->middleware('staff.can:taxonomy_manage');
    // Read access deliberately broadened to product_view rather than
    // taxonomy_manage: a Product Entry staff member (product_view +
    // product_manage only, no taxonomy_manage) must still be able to
    // list attribute definitions/values while building a variable
    // product — that is the entire reason that role exists. Flagged
    // for the domain owner as an explicit architectural call, not an
    // oversight.
    Route::get('/attribute-definitions', [AttributeDefinitionController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::get('/attribute-definitions/{id}/values', [AttributeValueController::class, 'index'])
        ->middleware('staff.can:product_view');
    Route::post('/attribute-values', [AttributeValueController::class, 'store'])
        ->middleware('staff.can:taxonomy_manage');

    Route::post('/brands', [BrandController::class, 'store'])
        ->middleware('staff.can:taxonomy_manage');
    Route::get('/brands', [BrandController::class, 'index'])
        ->middleware('staff.can:product_view');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('staff.can:taxonomy_manage');
    Route::get('/categories', [CategoryController::class, 'index'])
        ->middleware('staff.can:product_view');

    Route::post('/tags', [TagController::class, 'store'])
        ->middleware('staff.can:taxonomy_manage');
    Route::get('/tags', [TagController::class, 'index'])
        ->middleware('staff.can:product_view');

    // No promotion_view permission exists in the current vocabulary —
    // GET is gated identically to mutation. Real, visible consequence:
    // Manager (no promotion_manage per §4.1) cannot browse promotions
    // at all under this scheme. Not fixed here — inventing a new
    // permission is out of scope for this task. Flagged for the
    // domain owner.
    Route::post('/promotions', [PromotionController::class, 'store'])
        ->middleware('staff.can:promotion_manage');
    Route::get('/promotions', [PromotionController::class, 'index'])
        ->middleware('staff.can:promotion_manage');
    Route::post('/promotions/{promotionId}/scopes', [PromotionScopeController::class, 'store'])
        ->middleware('staff.can:promotion_manage');
    Route::get('/promotions/{promotionId}/scopes', [PromotionScopeController::class, 'index'])
        ->middleware('staff.can:promotion_manage');
    Route::delete('/promotions/{promotionId}/scopes/{scopeId}', [PromotionScopeController::class, 'destroy'])
        ->middleware('staff.can:promotion_manage');
});
