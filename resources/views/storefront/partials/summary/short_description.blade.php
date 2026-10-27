<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `short_description`: the short description (nothing when empty). The markup is the product page's own, byte for byte (its indentation included): the default layout must print the page the shop always printed. --}}
                @if ($product->shortDescription !== null && $product->shortDescription !== '')
                    <p class="sf-short-description">{{ $product->shortDescription }}</p>
                @endif
