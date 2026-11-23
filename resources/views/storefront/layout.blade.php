<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- LOGO-1: the site icon of the «Лого» tab (the same one the admin panel shows): the PNG 32, /favicon.ico and the iOS touch icon. With none set: the static SVG tag, byte for byte as before. --}}
    @if (($appearance['siteIcon'] ?? null) !== null)<link rel="icon" type="image/png" sizes="32x32" href="{{ $appearance['siteIcon']['png32']['url'] }}"><link rel="icon" href="/favicon.ico" sizes="any"><link rel="apple-touch-icon" href="{{ $appearance['siteIcon']['png180']['url'] }}">@else<link rel="icon" href="{{ asset('favicon.svg') }}">@endif

    <title>{{ $seo['title'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if (($seo['canonical'] ?? null) !== null)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    @if (($seo['canonical'] ?? null) === null || ($seo['noindex'] ?? false))
        <meta name="robots" content="{{ $seo['robots'] ?? 'noindex' }}">
    @endif
@php /* SEO-1: share cards and JSON-LD, see App\Storefront\Seo\SeoHead */ echo \App\Storefront\Seo\SeoHead::render($seo); @endphp
    @if ((file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json'))) && ! app()->runningUnitTests())
        @vite(['resources/css/storefront.css', 'resources/js/storefront.js'])
    @endif
    {{-- The merchant's appearance (D1): ONE small token stylesheet AFTER the main one (only when something differs from the defaults), and the body font's latin file preloaded. --}}
    @if ($appearance['preloadFont'] !== null)
        <link rel="preload" as="font" type="font/woff2" href="{{ $appearance['preloadFont'] }}" crossorigin>
    @endif
    @if ($appearance['tokensUrl'] !== null)
        <link rel="stylesheet" href="{{ $appearance['tokensUrl'] }}">
    @endif
    @if ($appearance['previewCss'] !== null)
        <link rel="stylesheet" href="{{ $appearance['previewCss'] }}">
    @endif
</head>
<body class="sf-layout">
    @include('storefront.partials.messages')
    {{-- DS-2b: on the cart, the checkout and the order-received page a PUBLISHED, enabled simplified header (logo, back link, phone) takes the place of the top banner and the full header;
         `checkoutHeaderForced` is the Design screen's preview, which is none of those routes. Off or invalid: the shop's full header, exactly as before. --}}
    @if (($appearance['checkoutHeader'] ?? null) !== null && (($appearance['checkoutHeaderForced'] ?? false) || request()->routeIs('storefront.cart', 'storefront.checkout', 'storefront.order.received')))
        @include('storefront.partials.checkout-header', ['values' => $appearance['checkoutHeader']])
    @else
        @include('storefront.partials.top-banner', ['banner' => $appearance['banner']])
        {{-- The header (D2): the published layout of the header builder, or the seeded default. --}}
        @include('storefront.header', ['header' => $appearance['header']])
    @endif
    <main class="sf-main">
        @yield('content')
    </main>
    {{-- The footer (FT-1): the published footer layout, or the old footer while none is published. --}}
    @include('storefront.layout.footer')@if (($appearance['cookies'] ?? null) !== null){{-- CC-1: the cookie banner (and the fallback strip) follow the footer on the SAME line, so nothing at all is printed while the banner is off (the page stays byte for byte what it was). One identical block for every visitor; the visitor's cookies are never read here. --}}@include('storefront.partials.cookie-banner', ['cc' => $appearance['cookies'], 'strip' => ! ($appearance['cookieMenuLink'] ?? false)])@endif
</body>
</html>
