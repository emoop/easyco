{{-- CD-1a: the schema of «Каталог»: what the section changes, in a line, and a small SCHEMATIC of the listing (admin only; the inline styles are allowed here and hold only closed-set digits and fixed values):
     N boxes per row on a desktop and the phone's count. The numbers are the validated working copy's, cast to a whole number. --}}
@php
    [$catalogValues] = \App\Storefront\PageDesign\PageDesign::validate(\App\Storefront\PageDesign\PageDesign::CATALOG, $this->tree['values'] ?? [], false);
    $desktopCols = (int) $catalogValues['cols'];
    $phoneCols = (int) $catalogValues['cols_phone'];
    $perPage = (int) $catalogValues['per_page'];
    $box = 'display:block;height:1.5rem;background:#e5e7eb;border-radius:2px';
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <p class="ds-note">{{ __('design.page_design.schema_catalog') }}</p>
    <div class="ds-catalog-sketch" aria-hidden="true" style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:flex-start">
        <div style="width:16rem;max-width:100%">
            <small>{{ __('design.page_design.devices.desktop') }}: {{ $desktopCols }}</small>
            <div style="display:grid;gap:4px;margin-top:4px;grid-template-columns:repeat({{ $desktopCols }}, 1fr)">
                @for ($i = 0; $i < $desktopCols * 2; $i++)<span style="{{ $box }}"></span>@endfor
            </div>
        </div>
        <div style="width:7rem;max-width:100%">
            <small>{{ __('design.page_design.devices.mobile') }}: {{ $phoneCols }}</small>
            <div style="display:grid;gap:4px;margin-top:4px;grid-template-columns:repeat({{ $phoneCols }}, 1fr)">
                @for ($i = 0; $i < $phoneCols * 2; $i++)<span style="{{ $box }}"></span>@endfor
            </div>
        </div>
    </div>
    <p class="ds-note">{{ __('design.page_design.fields.per_page') }}: {{ $perPage }}</p>
</section>
