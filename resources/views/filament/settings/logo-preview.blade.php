{{-- LOGO-1: the «Лого» tab's STATIC preview: a «browser tab» strip (the icon at 16 and 32 px beside the shop name) and the logo on a white strip as the top of an email shows it. It is drawn from the form's
     state (the chosen pictures' originals), so a choice is visible before the save. Nothing here writes anything. --}}
@php($preview = $this->logoPreview())
<div class="lg-preview">
    <style>
        .lg-preview { margin-top: .5rem; }
        .lg-preview h3 { margin: 0 0 .4rem; font-size: .8125rem; font-weight: 600; }
        .lg-strip { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: .5rem .75rem; border: 1px solid rgba(128, 128, 128, .35); border-radius: .5rem; background: #ffffff; color: #1f2937; font-size: .8125rem; }
        .lg-strip + h3 { margin-top: .9rem; }
        .lg-tab { display: inline-flex; align-items: center; gap: .4rem; max-width: 100%; padding: .3rem .6rem; border-radius: .4rem .4rem 0 0; background: #e5e7eb; }
        .lg-tab img { flex: none; image-rendering: auto; }
        .lg-tab span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .lg-checker { background: repeating-conic-gradient(#f3f4f6 0 25%, #ffffff 0 50%) 0 / 12px 12px; }
        .lg-mail img { display: block; max-width: 200px; max-height: 80px; height: auto; width: auto; }
        .lg-none { opacity: .6; }
    </style>
    <h3>{{ __('logo_admin.preview.tab') }}</h3>
    <div class="lg-strip">
        @if ($preview['icon'] !== null)
            <span class="lg-tab"><img src="{{ $preview['icon'] }}" alt="" width="16" height="16"><span>{{ $preview['name'] }}</span></span>
            <img src="{{ $preview['icon'] }}" alt="" width="32" height="32">
        @else
            <span class="lg-none">{{ __('logo_admin.empty_icon') }}</span>
            <span class="lg-tab"><span>{{ $preview['name'] }}</span></span>
        @endif
    </div>
    <h3>{{ __('logo_admin.preview.email') }}</h3>
    <div class="lg-strip lg-mail">
        @if ($preview['logo'] !== null)
            <img src="{{ $preview['logo'] }}" alt="{{ $preview['name'] }}">
        @else
            <span class="lg-none">{{ __('logo_admin.preview.none') }}</span>
        @endif
    </div>
</div>
