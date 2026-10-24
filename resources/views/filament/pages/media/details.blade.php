{{-- The details slide-over of the «Медия» grid (ML-1a). $asset is a catalog_media row, $state its state, $preview the MEDIUM variant's address (never the original), $copyUrl the LARGE variant's
     address (null = not ready: the button is disabled), $uploader a staff name or null, $usages the list of App\Media\Usage\MediaUsage. Every printed value is escaped. --}}
@php
    $size = (int) $asset->size_bytes;
    $sizeText = $size >= 1048576 ? number_format($size / 1048576, 2).' MB' : number_format($size / 1024, 1).' KB';
@endphp
<div class="ml-details">
    <div class="ml-preview ml-thumb-{{ $state }}">
        @if ($preview !== null)
            <img src="{{ $preview }}" alt="{{ $asset->alt_text }}" decoding="async">
        @elseif ($state === 'video')
            <x-filament::icon icon="heroicon-o-film" class="ml-icon" />
        @else
            <span class="ml-state">{{ __('media.library.state.'.$state) }}</span>
        @endif
    </div>

    <dl class="ml-facts">
        <dt>{{ __('media.library.details.name') }}</dt>
        <dd>{{ $asset->original_name ?: basename((string) $asset->path) }}</dd>
        <dt>{{ __('media.library.details.type') }}</dt>
        <dd>{{ in_array($asset->type, ['video', 'social_video'], true) ? __('media.library.kind.video') : __('media.library.kind.image') }}@if ($asset->mime) · {{ $asset->mime }}@endif</dd>
        <dt>{{ __('media.library.details.size') }}</dt>
        <dd>{{ $size > 0 ? $sizeText : '—' }}</dd>
        <dt>{{ __('media.library.details.dimensions') }}</dt>
        <dd>@if ($asset->width && $asset->height){{ (int) $asset->width }}×{{ (int) $asset->height }}@else — @endif</dd>
        <dt>{{ __('media.library.details.uploaded') }}</dt>
        <dd>{{ $uploader ?? '—' }} · {{ $asset->created_at?->format('d.m.Y H:i') }}</dd>
        <dt>{{ __('media.library.details.status') }}</dt>
        <dd>{{ __('media.library.state.'.$state) }}@if ($asset->processing_failure_reason) — {{ $asset->processing_failure_reason }}@endif</dd>
    </dl>

    <div class="ml-copy">
        <button type="button" class="ml-btn" @disabled($copyUrl === null) @if ($copyUrl !== null) x-data x-on:click="navigator.clipboard.writeText(@js($copyUrl)); $el.dataset.done = '1'" @endif>{{ __('media.library.details.copy') }}</button>
        @if ($copyUrl === null)
            <span class="ml-muted">{{ __('media.library.details.copy_disabled') }}</span>
        @endif
    </div>

    <section class="ml-usage" aria-labelledby="ml-usage-h">
        <h3 id="ml-usage-h">{{ __('media.library.details.usage') }}</h3>
        @if ($usages === [])
            <p class="ml-muted">{{ __('media.library.details.no_usage') }}</p>
        @else
            <ul>
                @foreach ($usages as $usage)
                    <li>
                        @if ($usage->editUrl !== null)
                            <a href="{{ $usage->editUrl }}">{{ $usage->label }}</a>
                        @else
                            {{ $usage->label }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="ml-muted ml-soon">{{ __('media.library.details.delete_soon') }}</p>
</div>
