<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    @if (($seo['canonical'] ?? null) !== null)
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @else
        <meta name="robots" content="noindex">
    @endif
</head>
<body class="sf-layout">
    <header class="sf-header">
        <a class="sf-logo" href="{{ route('storefront.home', [], false) }}">{{ config('app.name') }}</a>
        @if (! empty($categories))
            <nav class="sf-nav" aria-label="{{ __('storefront.menu') }}">
                @include('storefront.partials.menu', ['nodes' => $categories])
            </nav>
        @endif
    </header>
    <main class="sf-main">
        @yield('content')
    </main>
    <footer class="sf-footer"></footer>
</body>
</html>
