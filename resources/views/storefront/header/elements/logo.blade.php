{{-- The shop logo; its height is the CSS variable --sf-logo-d-h / --sf-logo-m-h of the token stylesheet (never an inline style). LG-1: when the merchant chose a picture (a PNG or WebP of the library) the compiled rendition is printed with its width and height (no layout shift) and no loading="lazy" (it is above the fold); otherwise the static file, byte for byte as before. The alt is the site name either way. --}}
<a class="sf-logo sf-logo-{{ $context }}" href="{{ route('storefront.home', [], false) }}">
    @if (isset($settings['logo_image']))<img src="{{ $settings['logo_image']['url'] }}" alt="{{ config('app.name') }}" width="{{ (int) $settings['logo_image']['width'] }}" height="{{ (int) $settings['logo_image']['height'] }}" decoding="async">
@else<img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}" width="596" height="150">
@endif
</a>
