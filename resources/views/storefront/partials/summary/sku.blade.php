<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `sku`: the product's SKU as a small muted line (nothing when it is empty). New markup. --}}
                @if ($product->sku !== null && $product->sku !== '')
                    <p class="sf-sku">{{ __('design.summary.sku_label') }} {{ $product->sku }}</p>
                @endif
