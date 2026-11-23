{{-- CC-1 (cookie-consent.md): the cookie / ads consent banner. Rendered ONLY while `cookies.enabled` ($cc is null otherwise: no markup, no script, no footer link). The SAME HTML for every visitor and every cache layer:
     both layers are here (the inactive one `hidden`), the whole banner is `hidden` until storefront-consent.js has read the cookie. The server never reads that cookie.
     No inline script, no `style=`, no `on*=`: the look is storefront.css, the buttons' colour is the token stylesheet's `--sf-cc-btn`.
     $cc = {position, view, months, version, texts (the shop language's), policy}. $preview = the admin's static preview (visible, no script, no footer link): `$previewLayer`, `$previewBack`. --}}
@php
    $preview = $preview ?? false;
    $previewLayer = $previewLayer ?? 'summary';
    $previewBack = $previewBack ?? false;
    $t = $cc['texts'];
    $modal = $cc['position'] === 'center';
    $startLayer = $preview ? $previewLayer : ($cc['view'] === 'details' ? 'details' : 'summary');
    $categories = [['id' => 'ads', 'title' => $t['ads_title'], 'text' => $t['ads_text']]];
@endphp
{{-- the FALLBACK strip (the footer's bottom bar): printed only while NO menu a guest sees (header, drawer, footer) holds the «Настройки за бисквитки» item; once the merchant adds that item the strip is gone --}}
@if (! $preview && ($strip ?? true))
    <div class="sf-cc-bar" data-sf-cc-bar hidden>
        <div class="sf-cc-bar-inner">
            <button type="button" class="sf-cc-reopen" data-sf-cc-action="open">{{ $t['footer_link'] }}</button>
        </div>
    </div>
@endif
<div class="sf-cc sf-cc-{{ $cc['position'] }}" data-sf-cc data-version="{{ $cc['version'] }}" data-months="{{ $cc['months'] }}" data-view="{{ $cc['view'] }}" data-modal="{{ $modal ? '1' : '0' }}"@unless ($preview) hidden @endunless>
    <div class="sf-cc-dialog" role="dialog"@if ($modal) aria-modal="true"@endif aria-labelledby="sf-cc-title-{{ $startLayer }}" tabindex="-1" data-sf-cc-dialog>
        <div class="sf-cc-layer sf-cc-layer-summary" data-sf-cc-layer="summary"@if ($startLayer !== 'summary') hidden @endif>
            <div class="sf-cc-copy">
                <h2 class="sf-cc-title" id="sf-cc-title-summary">{{ $t['title'] }}</h2>
                <p class="sf-cc-text">{{ $t['text'] }}@if ($cc['policy'] !== null) <a class="sf-cc-policy" href="/info/{{ rawurlencode($cc['policy']['slug']) }}">{{ $cc['policy']['title'] }}</a>@endif</p>
            </div>
            <div class="sf-cc-side">
                <div class="sf-cc-actions">
                    <button type="button" class="sf-cc-btn" data-sf-cc-action="accept">{{ $t['accept_all'] }}</button>
                    <button type="button" class="sf-cc-btn" data-sf-cc-action="reject">{{ $t['necessary_only'] }}</button>
                </div>
                <button type="button" class="sf-cc-link" data-sf-cc-action="details">{{ $t['settings_link'] }}</button>
            </div>
        </div>
        <div class="sf-cc-layer sf-cc-layer-details" data-sf-cc-layer="details"@if ($startLayer !== 'details') hidden @endif>
            <button type="button" class="sf-cc-back" data-sf-cc-action="back"@if (! ($preview && $previewBack)) hidden @endif>{{ $t['back'] }}</button>
            <h2 class="sf-cc-title" id="sf-cc-title-details">{{ $t['layer2_title'] }}</h2>
            <ul class="sf-cc-cats">
                <li class="sf-cc-cat">
                    <div class="sf-cc-cat-copy">
                        <h3 class="sf-cc-cat-title">{{ $t['necessary_title'] }}</h3>
                        <p class="sf-cc-cat-text">{{ $t['necessary_text'] }}</p>
                    </div>
                    <span class="sf-cc-always">{{ $t['always_on'] }}</span>
                </li>
                @foreach ($categories as $category)
                    <li class="sf-cc-cat">
                        <div class="sf-cc-cat-copy">
                            <h3 class="sf-cc-cat-title" id="sf-cc-cat-{{ $category['id'] }}">{{ $category['title'] }}</h3>
                            <p class="sf-cc-cat-text">{{ $category['text'] }}</p>
                        </div>
                        <button type="button" class="sf-cc-switch" role="switch" aria-checked="false" aria-labelledby="sf-cc-cat-{{ $category['id'] }}" data-sf-cc-switch="{{ $category['id'] }}"></button>
                    </li>
                @endforeach
            </ul>
            <div class="sf-cc-actions">
                <button type="button" class="sf-cc-btn" data-sf-cc-action="accept">{{ $t['accept_all'] }}</button>
                <button type="button" class="sf-cc-btn" data-sf-cc-action="reject">{{ $t['necessary_only'] }}</button>
            </div>
            <button type="button" class="sf-cc-btn sf-cc-btn-full" data-sf-cc-action="save">{{ $t['save'] }}</button>
        </div>
    </div>
</div>
@unless ($preview)
    {{-- storefront-consent.js: the only script of the banner (a Vite file; under the test runner, where Vite is not built, the plain tag keeps the markup testable) --}}
    @if ((file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json'))) && ! app()->runningUnitTests())
        @vite(['resources/js/storefront-consent.js'])
    @else
        <script type="module" src="/build/assets/storefront-consent.js"></script>
    @endif
@endunless
