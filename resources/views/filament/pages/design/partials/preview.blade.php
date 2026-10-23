<section class="ds-preview" aria-labelledby="ds-preview-title">
    <h2 class="ds-h" id="ds-preview-title">{{ $zoneObject->previewTitle() }}</h2>
    {{-- The desktop layout needs a viewport of at least 1024px, the column is narrower: the frame is 1280px wide and Alpine zooms it down to the column (admin only; the phone frame is 390px as it is). --}}
    <div class="ds-frame ds-frame-{{ $zoneObject->hasDevices() ? $this->device : 'desktop' }}" @if ($this->device === 'desktop' || ! $zoneObject->hasDevices()) x-data="{ k: 1 }" x-init="const fit = () => k = Math.min(1, $el.clientWidth / 1280); fit(); new ResizeObserver(fit).observe($el)" @endif>
        <iframe wire:key="ds-preview-{{ md5($this->previewUrl) }}-{{ $this->device }}" title="{{ $zoneObject->previewTitle() }}" src="{{ $this->previewUrl }}" loading="lazy" @if ($zoneObject->previewScroll() !== '') x-on:load="const w = $el.contentWindow; const t = w.document.querySelector('{{ $zoneObject->previewScroll() }}'); t && w.scrollTo(0, t.getBoundingClientRect().top + w.scrollY)" @endif @if ($this->device === 'desktop' || ! $zoneObject->hasDevices()) x-bind:style="{ zoom: k }" @endif></iframe>
    </div>
    <p class="ds-note"><a href="{{ $this->previewUrl }}" target="_blank" rel="noopener">{{ __('design.preview.open') }}</a></p>
</section>
