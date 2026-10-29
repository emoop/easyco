<?php print("\x01"); ?>{{-- SC-1: the marker keeps Laravel's ltrim of a view's output from eating the first line's indentation; product.blade.php removes it. --}}{{-- PB-1, right column component `purchase`: the attribute pickers (colour circles, size buttons; the page's select stays the source of truth), the quantity counter and the add-to-cart button, ONE block. Texts are escaped; the script reads ids and flags from ONE data attribute; no style attribute: a colour is a validated `data-colour` the script applies. --}}
@php
    $buy = \App\Storefront\ProductSummary\PurchaseView::build($product, $component['settings'] ?? []);
    $texts = \App\Storefront\ProductSummary\PurchaseView::labels($component['settings'] ?? []);
    $variable = $product->type === 'variable' && $product->variations !== [];
    $soldOut = $variable && $buy['orderable'] === 0;
    $showQuantity = ($component['settings']['show_quantity'] ?? true) !== false;
@endphp
                <div class="sf-purchase" data-sf-purchase="{{ $buy['json'] }}">
                @if ($variable)
                    <div class="sf-variations{{ $buy['pickers'] ? ' sf-variations--pickers' : '' }}">
                        @if ($buy['pickers'])
                            @foreach ($buy['groups'] as $g => $group)
                                <div class="sf-picker" data-sf-group="{{ $g }}" data-sf-type="{{ $group['type'] }}">
                                    <span class="sf-picker-label" id="sf-pg-{{ $g }}">{{ $group['name'] }}</span>
                                    @if ($group['type'] === 'swatch' || $group['type'] === 'buttons')
                                        <div class="sf-picker-values" role="radiogroup" aria-labelledby="sf-pg-{{ $g }}">
                                            @foreach ($group['values'] as $j => $value)
                                                @php
                                                    $round = $group['type'] === 'swatch' && $value['colour'] !== null;
                                                @endphp
                                                <button type="button" role="radio" class="{{ $round ? 'sf-swatch' : 'sf-size' }}" aria-checked="false" data-sf-value="{{ $j }}" title="{{ $value['label'] }}"{!! $round ? ' data-colour="'.e($value['colour']).'"' : '' !!}>
                                                    @if ($round)<span class="sf-swatch-dot" aria-hidden="true"></span><span class="sf-sr">{{ $value['label'] }}</span>@else{{ $value['label'] }}@endif
                                                </button>
                                            @endforeach
                                        </div>
                                    @else
                                        <select class="sf-picker-select" aria-labelledby="sf-pg-{{ $g }}" data-sf-select>
                                            <option value="">{{ $group['name'] }}</option>
                                            @foreach ($group['values'] as $j => $value)
                                                <option value="{{ $j }}">{{ $value['label'] }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            @endforeach
                            <button type="button" class="sf-clear" data-sf-clear hidden>{{ __('storefront.purchase.clear') }}</button>
                        @endif
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
                        <div class="sf-buy-row">
                        @if ($showQuantity)
                            <div class="sf-qty" data-sf-qty>
                                <div class="sf-qty-box">
                                    <button type="button" class="sf-qty-btn" data-sf-qty-minus aria-label="{{ __('storefront.purchase.decrease') }}">−</button>
                                    <input class="sf-qty-input" id="sf-qty" type="text" inputmode="numeric" autocomplete="off" value="1" aria-label="{{ __('storefront.purchase.quantity') }}" data-sf-qty-input>
                                    <button type="button" class="sf-qty-btn" data-sf-qty-plus aria-label="{{ __('storefront.purchase.increase') }}">+</button>
                                </div>
                            </div>
                        @endif
                            <button class="sf-add-to-cart sf-buy" type="button" disabled data-variation-id="{{ $purchasable?->id }}" data-sf-label="{{ $texts['label'] }}" data-sf-label-choose="{{ $texts['choose'] }}" data-sf-label-sold-out="{{ $texts['sold_out'] }}">{{ $soldOut ? $texts['sold_out'] : $texts['label'] }}</button>
                        </div>
                        <p class="sf-cart-status" role="status" aria-live="polite" data-sf-cart-status></p>
                    </div>
                </div>
