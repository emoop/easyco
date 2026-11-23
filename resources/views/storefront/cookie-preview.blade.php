<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('cookies_admin.preview.title') }}</title>
    @if ((file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json'))) && ! app()->runningUnitTests())
        @vite(['resources/css/storefront.css'])
    @endif
    @if ($tokensUrl !== null)
        <link rel="stylesheet" href="{{ $tokensUrl }}">
    @endif
    @if ($previewCss !== null)
        <link rel="stylesheet" href="{{ $previewCss }}">
    @endif
</head>
<body class="sf-layout">
    {{-- CC-1: the admin's static preview. No script is loaded here and no cookie is touched: the buttons do nothing. --}}
    @include('storefront.partials.cookie-banner', ['cc' => $cc, 'preview' => true, 'previewLayer' => $layer, 'previewBack' => $layer === 'details'])
</body>
</html>
