<section class="ds-preview" aria-labelledby="ds-preview-title">
    <h2 class="ds-h" id="ds-preview-title">{{ __('design.preview.title') }}</h2>
    {{-- The desktop layout needs a viewport of at least 1024px, the column is narrower: the frame is 1280px wide and Alpine zooms it down to the column (admin only; the phone frame is 390px as it is). --}}
    <div class="ds-frame ds-frame-{{ $this->device }}" @if ($this->device === 'desktop') x-data="{ k: 1 }" x-init="const fit = () => k = Math.min(1, $el.clientWidth / 1280); fit(); new ResizeObserver(fit).observe($el)" @endif>
        <iframe wire:key="ds-preview-{{ md5($this->previewUrl) }}-{{ $this->device }}" title="{{ __('design.preview.title') }}" src="{{ $this->previewUrl }}" loading="lazy" @if ($this->device === 'desktop') x-bind:style="{ zoom: k }" @endif></iframe>
    </div>
    <p class="ds-note"><a href="{{ $this->previewUrl }}" target="_blank" rel="noopener">{{ __('design.preview.open') }}</a></p>
</section>
