{{-- The SIMPLIFIED header of the cart, the checkout and the order-received page (DS-2b): the back link on the left, the shop logo in the middle, an optional phone on the right. Not sticky. The colours and the
     logo heights are CSS variables of the token stylesheet (--sf-chdr-bg, --sf-chdr-fg, --sf-chdr-logo-d-h, --sf-chdr-logo-m-h), never a style attribute. `$values` is validated: the link is a path in the
     shop or an https address, the phone an international number (the `tel:` link keeps only + and digits). Text is escaped. --}}
@php
    $label = $values['back_label'] !== '' ? $values['back_label'] : __('storefront.back_to_shop');
    $tel = preg_replace('/[^0-9+]/', '', (string) $values['phone']);
@endphp
<header class="sf-chdr" data-sf-checkout-header>
    <div class="sf-chdr-inner">
        <a class="sf-chdr-back" href="{{ $values['back_url'] }}" aria-label="{{ $label }}">
            <svg class="sf-chdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>
            <span class="sf-chdr-back-label">{{ $label }}</span>
        </a>
        <a class="sf-logo sf-chdr-logo" href="{{ route('storefront.home', [], false) }}">
            <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}" width="596" height="150">
        </a>
        <div class="sf-chdr-phone">
            @if ($tel !== '')
                <a class="sf-chdr-tel" href="tel:{{ $tel }}" aria-label="{{ __('storefront.call_us') }}: {{ $values['phone'] }}">
                    <svg class="sf-chdr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>
                    <span class="sf-chdr-tel-text">{{ $values['phone'] }}</span>
                </a>
            @endif
        </div>
    </div>
</header>
