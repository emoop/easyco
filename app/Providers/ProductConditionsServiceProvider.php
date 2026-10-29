<?php

namespace App\Providers;

use App\Storefront\Conditions\CurrentProduct;
use App\Storefront\Conditions\ProductConditions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * HB-2: the product predicates for a theme. `@productIs('on_sale') … @elseproductIs('in_category', 12) … @endproductIs` (Blade::if) asks the SAME predicates the «Показвай, когато…» conditions of an
 * HTML block use (ProductConditions), for the product of the page being rendered (CurrentProduct, one per request). Outside a product page every predicate is false; a name that does not exist
 * throws outside production and is false in production.
 */
final class ProductConditionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped, not singleton: the product of one request never reaches the next one in a long-lived worker
        $this->app->scoped(CurrentProduct::class);
    }

    public function boot(): void
    {
        Blade::if('productIs', static fn (string $name, mixed ...$params): bool => ProductConditions::is($name, ...$params));
    }
}
