{{-- CC-1: the live preview: the REAL banner partial in a frame (static and inert: no script, no cookie). The layer is the page's own control; position, view, colour and texts are the form's draft. --}}
<div class="cc-preview">
    <style>
        .cc-preview { position: sticky; top: 1rem; }
        .cc-preview-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: .5rem; font-size: .875rem; }
        .cc-preview-frame { display: block; width: 100%; height: 36rem; border: 1px solid rgba(128, 128, 128, .35); border-radius: .5rem; background: #f5f5f5; }
        .cc-preview-note { margin-top: .35rem; font-size: .75rem; opacity: .7; }
    </style>
    <x-filament::section compact :heading="__('cookies_admin.preview.title')">
        <div class="cc-preview-bar">
            <label for="cc-layer">{{ __('cookies_admin.preview.layer') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="cc-layer" wire:model.live="data.preview_layer">
                    <option value="summary">{{ __('cookies_admin.preview.layer_summary') }}</option>
                    <option value="details">{{ __('cookies_admin.preview.layer_details') }}</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <iframe class="cc-preview-frame" src="{{ $this->previewSrc }}" title="{{ __('cookies_admin.preview.frame') }}" loading="lazy" referrerpolicy="same-origin"></iframe>
        <p class="cc-preview-note">{{ __('cookies_admin.preview.inert') }}</p>
    </x-filament::section>
</div>
