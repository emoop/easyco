{{-- The «Топбанер» schema (DS-2b): ONE box, a miniature of the banner on its colours. No palette, no rows. The colours are checked (#rrggbb) before they reach the style. --}}
@php
    $values = $this->tree['values'] ?? [];
    $bg = \App\Settings\AppearanceSettings::isHex($values['bg_color'] ?? null) ? $values['bg_color'] : '#171717';
    $fg = \App\Settings\AppearanceSettings::isHex($values['text_color'] ?? null) ? $values['text_color'] : '#ffffff';
    $on = (bool) ($values['enabled'] ?? false);
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <div class="ds-mini @unless ($on) ds-off @endunless">
        <div class="ds-mini-banner" style="background-color: {{ $bg }}; color: {{ $fg }};">
            <span>{{ $values['text'] ?? '' }}</span>
            @if (($values['link_label'] ?? '') !== '')
                <span class="ds-mini-link">{{ $values['link_label'] }}</span>
            @endif
        </div>
        @unless ($on)
            <span class="ds-tag">{{ __('design.schema.disabled') }}</span>
        @endunless
    </div>
</section>
