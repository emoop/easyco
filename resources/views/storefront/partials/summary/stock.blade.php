<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `stock`: the in-stock / out-of-stock line. The markup is the product page's own, byte for byte (its indentation included): the default layout must print the page the shop always printed. --}}
                @if ($product->inStock)
                    <p class="sf-stock sf-stock-in">{{ __('storefront.in_stock') }}</p>
                @else
                    <p class="sf-stock sf-stock-out">{{ __('storefront.out_of_stock') }}</p>
                @endif
