{{-- One card of the «Медия» grid (ML-1a). $getRecord() is a catalog_media row; $uses is the page's one batched usage count for this card (viewData of the column). Nothing here touches the disk: the state comes from the row (MediaResource::stateOf),
     the preview is the THUMBNAIL variant's address, the usage badge reads the page's one batched count. Every printed value is escaped. --}}
@php
    $asset = $getRecord();
    $state = \App\Filament\Resources\MediaResource::stateOf($asset);
    $thumb = \App\Filament\Resources\MediaResource::previewUrl($asset, 'thumbnail');
    $name = (string) ($asset->original_name ?: basename((string) $asset->path));
@endphp
<div class="ml-card" title="{{ $name }}">
    <div class="ml-thumb ml-thumb-{{ $state }}">
        @if ($thumb !== null)
            <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" loading="lazy" decoding="async" width="{{ (int) ($asset->width ?: 400) }}" height="{{ (int) ($asset->height ?: 400) }}">
        @elseif ($state === 'video')
            <x-filament::icon icon="heroicon-o-film" class="ml-icon" />
        @else
            <span class="ml-state">{{ __('media.library.state.'.$state) }}</span>
        @endif
    </div>
    <div class="ml-meta">
        <span class="ml-name">{{ \Illuminate\Support\Str::limit($name, 28) }}</span>
        <span class="ml-dim">@if ($asset->width && $asset->height){{ (int) $asset->width }}×{{ (int) $asset->height }}@else — @endif</span>
        <span class="ml-badge @if ($uses === 0) ml-badge-unused @endif">{{ $uses === 0 ? __('media.library.unused') : trans_choice('media.library.places', $uses, ['n' => $uses]) }}</span>
    </div>
</div>
