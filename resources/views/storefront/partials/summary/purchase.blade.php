<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- SC-1, right column component `purchase`: TODAY's variation select (variable products) and the add-to-cart box, ids and data attributes unchanged. The markup is the product page's own, byte for byte (its indentation included): the default layout must print the page the shop always printed. --}}
                @if ($product->type === 'variable' && $product->variations !== [])
                    <div class="sf-variations">
                        <label class="sf-variation-label" for="sf-variation">{{ __('storefront.choose_variation') }}</label>
                        <select class="sf-variation-select" id="sf-variation" name="variation">
                            @foreach ($product->variations as $variation)
                                @php
                                    $available = $variation->purchasable && $variation->inStock;
                                    $priceText = $variation->price !== null ? $prices->format($variation->price->fromMinor, $variation->price->currency) : '';
                                @endphp
                                <option value="{{ $variation->id }}" @disabled(! $available)>
                                    @if ($priceText === '')
                                        {{ $variation->label }}
                                    @elseif ($available)
                                        {{ __('storefront.variation_option', ['label' => $variation->label, 'price' => $priceText]) }}
                                    @else
                                        {{ __('storefront.variation_option_unavailable', ['label' => $variation->label, 'price' => $priceText, 'reason' => __('storefront.unavailable')]) }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="sf-add-to-cart-box" data-sf-add-to-cart>
                    <button class="sf-add-to-cart" type="button" disabled data-variation-id="{{ $purchasable?->id }}">{{ __('storefront.add_to_cart') }}</button>
                    <p class="sf-cart-status" role="status" aria-live="polite" data-sf-cart-status></p>
                </div>
