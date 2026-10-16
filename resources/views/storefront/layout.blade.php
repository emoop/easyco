<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The same favicon the admin panel shows (AdminPanelProvider), so a storefront tab is recognisable too. --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <title>{{ $seo['title'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if (($seo['canonical'] ?? null) !== null)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    @if (($seo['canonical'] ?? null) === null || ($seo['noindex'] ?? false))
        <meta name="robots" content="noindex">
    @endif
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
    @include('storefront.partials.top-banner', ['banner' => $appearance['banner']])
    {{-- The header (D2): the published layout of the header builder, or the seeded default. --}}
    @include('storefront.header', ['header' => $appearance['header']])
    <main class="sf-main">
        @yield('content')
    </main>
    <footer class="sf-footer">
        <div class="sf-footer-inner">
            <div class="sf-footer-brand">
                <span class="sf-footer-name">{{ config('app.name') }}</span>
                <a class="sf-footer-home" href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a>
            </div>
            {{-- The content pages flagged for the footer (pages-design.md section 4): published only, titles escaped, links built by route(). --}}
            @if (! empty($footerPages))
                <nav class="sf-footer-pages" aria-label="{{ __('storefront.footer_pages') }}">
                    <ul class="sf-footer-list">
                        @foreach ($footerPages as $footerPage)
                            <li><a href="{{ $footerPage['url'] }}">{{ $footerPage['title'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>
    </footer>
</body>
</html>
