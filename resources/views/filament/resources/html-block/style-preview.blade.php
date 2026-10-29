{{-- HB-1: the live preview of «Стил на блока» in the block's form. The ONLY inline style of the feature, on an ADMIN page, built from values HtmlBlockStyle::previewDeclarations validated (a field that does not validate is left out); the storefront never prints one. --}}
<div class="fi-hb-preview" style="padding: 0.5rem 0;">
    <div style="{{ $declarations }}">{{ $sample }}</div>
</div>