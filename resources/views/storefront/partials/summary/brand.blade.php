<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `brand`: the brand line (nothing when the product has no brand). The markup is the product page's own, byte for byte (its indentation included): the default layout must print the page the shop always printed. --}}
                @if ($product->brand !== null)
                    <p class="sf-product-brand">{{ $product->brand['name'] }}</p>
                @endif
