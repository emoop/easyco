<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        D7: the sandbox must never be indexed. This meta is one of the TWO
        independent mechanisms (the other is the X-Robots-Tag response
        header set by App\Sandbox\Http\Middleware\NoIndexHeaders) — a
        crawler that honours only one of them still gets told. Both are
        asserted by tests, not assumed.
    --}}
    <meta name="robots" content="noindex">

    <title>@yield('title', 'Sandbox') — EasyCo sandbox</title>

    {{-- Minimal inline CSS, deliberately: D7 requires no build step, no
         framework and no JavaScript. Nothing here is shared with the real
         storefront (which does not exist yet) and nothing here is worth a
         Vite asset pipeline for a preview surface. --}}
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2328; background: #fff; line-height: 1.5; }
        a { color: #0b5cad; }
        .banner { background: #7a1d1d; color: #fff; padding: .6rem 1rem; font-weight: 600; font-size: .9rem; letter-spacing: .02em; text-align: center; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        header.site { border-bottom: 1px solid #e4e7eb; }
        header.site .wrap { padding-top: 1rem; padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
        header.site .name { font-weight: 700; }
        header.site .note { color: #57606a; font-size: .85rem; }
        h1 { font-size: 1.6rem; margin: 0 0 .5rem; }
        .muted { color: #57606a; font-size: .9rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.25rem; margin-top: 1.25rem; }
        .card { border: 1px solid #e4e7eb; border-radius: 8px; padding: .75rem; display: flex; flex-direction: column; gap: .4rem; }
        .card .thumb { display: block; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: #f3f4f6; border-radius: 6px; }
        .thumb-placeholder { display: flex; align-items: center; justify-content: center; width: 100%; aspect-ratio: 1 / 1; background: #f3f4f6; color: #8c959f; border-radius: 6px; font-size: .85rem; }
        .card h2 { font-size: 1rem; margin: 0; }
        .card .brand { margin: 0; font-size: .85rem; color: #57606a; }
        .card .price { margin: 0; font-weight: 600; }
        .card s { color: #57606a; font-weight: 400; }
        button[disabled] { align-self: flex-start; padding: .35rem .7rem; border: 1px dashed #b9c0c8; background: #f6f8fa; color: #768390; border-radius: 6px; font-size: .8rem; cursor: not-allowed; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: .92rem; }
        th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
        th { background: #f6f8fa; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #57606a; }
        .axis { display: inline-block; background: #eef2f6; border-radius: 999px; padding: .1rem .5rem; margin: 0 .25rem .25rem 0; font-size: .82rem; white-space: nowrap; }
        .gallery { display: flex; flex-wrap: wrap; gap: .6rem; margin: 1rem 0 0; padding: 0; list-style: none; }
        .gallery li { margin: 0; }
        .gallery img { display: block; width: 140px; height: 140px; object-fit: cover; border: 1px solid #e4e7eb; border-radius: 6px; background: #f3f4f6; }
        .pagination { display: flex; align-items: center; gap: 1rem; margin-top: 1.5rem; }
        .pagination .disabled { color: #8c959f; }
        .not-found { border: 1px solid #e4e7eb; border-left: 4px solid #7a1d1d; border-radius: 8px; padding: 1.25rem; margin-top: 1.5rem; }
        footer.site { border-top: 1px solid #e4e7eb; color: #57606a; font-size: .85rem; }
        code { background: #f6f8fa; padding: .1rem .3rem; border-radius: 4px; }
    </style>
</head>
<body>
    {{-- Unmissable on purpose: a real product page that looks like the real
         storefront is exactly the thing that would be mistaken for one. --}}
    <div class="banner">SANDBOX — not the real storefront. Read-only preview of real catalog data.</div>

    <header class="site">
        <div class="wrap">
            <span class="name"><a href="{{ route('sandbox.index') }}">EasyCo sandbox</a></span>
            <span class="note">noindex · no cart · no checkout</span>
        </div>
    </header>

    <main class="wrap">
        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">
            Read-only preview. Prices come from the real Pricing domain, stock from the real Inventory domain —
            nothing on these pages writes anything.
        </div>
    </footer>
</body>
</html>
