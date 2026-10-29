<?php

namespace App\Storefront\Conditions;

/**
 * The product of the page being rendered, for ONE request (a scoped binding, ProductConditionsServiceProvider): the product page fills it, `@productIs` and the conditions of an HTML block read it.
 * Outside a product page it is empty, and every predicate is then simply false.
 */
final class CurrentProduct
{
    private ?ProductContext $context = null;

    public function set(?ProductContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ?ProductContext
    {
        return $this->context;
    }
}
