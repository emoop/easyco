<?php

namespace Tests\Concerns;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\ShippingClass;
use Tests\Support\ClassGivingProductRepository;

/**
 * For the tests of the product admin forms written before the class became required (shipping stage 5e): the store
 * gets a DEFAULT class "standard" (so a create form pre-fills it) and every fixture product saved through the
 * repository without a class is given it (so an edit form can be saved). Call giveProductsAShippingClass() from setUp()
 * AFTER parent::setUp() and the database refresh.
 */
trait GivesProductsAShippingClass
{
    protected function giveProductsAShippingClass(): void
    {
        $classes = app(ShippingClassRepository::class);
        $class = ShippingClass::create('Standard', 'standard');
        $classes->save($class);
        $classes->markDefault((string) $class->id());

        $this->app->extend(ProductRepository::class, fn (ProductRepository $inner): ProductRepository => new ClassGivingProductRepository($inner, 'standard'));
    }
}
