{{-- The texts and the price template the cart script needs, as data attributes (no inline script on a storefront page). --}}
<div hidden id="sf-i18n" data-sf-i18n
     @foreach (array_merge(trans('storefront.js'), trans('storefront.js_checkout')) as $key => $text)
         data-m-{{ str_replace('_', '-', $key) }}="{{ $text }}"
     @endforeach
     data-m-cart-count-label="{{ __('storefront.cart_count_label') }}"
     @isset($priceTemplate)
         data-price-template="{{ $priceTemplate['template'] }}"
         data-price-zero="{{ $priceTemplate['zero'] }}"
         data-price-currency="{{ $priceTemplate['currency'] }}"
     @endisset
     data-cart-url="{{ route('storefront.cart', [], false) }}"
     data-checkout-url="{{ route('storefront.checkout', [], false) }}"></div>
