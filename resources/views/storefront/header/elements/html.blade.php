{{-- Merchant HTML: cleaned by DescriptionSanitizer on save AND here, on render (a row edited by hand cannot carry a script). --}}
@if ($settings['html'] !== '')
    <div class="sf-hdr-html">{!! \App\Storefront\Support\DescriptionSanitizer::clean($settings['html']) !!}</div>
@endif
