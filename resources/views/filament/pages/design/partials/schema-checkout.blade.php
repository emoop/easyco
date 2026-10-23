{{-- The «Количка и поръчка» schema (DS-2b): ONE box, a miniature of the three columns of the simplified header. The colours are checked (#rrggbb) before they reach the style. --}}
@php
    $values = $this->tree['values'] ?? [];
    $bg = \App\Settings\AppearanceSettings::isHex($values['bg'] ?? null) ? $values['bg'] : '#ffffff';
    $fg = \App\Settings\AppearanceSettings::isHex($values['fg'] ?? null) ? $values['fg'] : '#171717';
    $on = (bool) ($values['enabled'] ?? false);
    $label = ($values['back_label'] ?? '') !== '' ? $values['back_label'] : __('design.checkout.default_label');
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>
    <div class="ds-mini @unless ($on) ds-off @endunless">
        <div class="ds-mini-cols" style="background-color: {{ $bg }}; color: {{ $fg }};">
            <span>← {{ $label }}</span>
            <strong>{{ config('app.name') }}</strong>
            <span>{{ $values['phone'] ?? '' }}</span>
        </div>
        @unless ($on)
            <span class="ds-tag">{{ __('design.schema.disabled') }}</span>
        @endunless
        <p class="ds-note">{{ __('design.checkout.pages') }}</p>
    </div>
</section>
