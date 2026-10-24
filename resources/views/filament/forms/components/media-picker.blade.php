{{-- The media picker field (ML-2): app/Filament/Forms/Components/MediaPicker.php. A picture: 96px thumbnail, its name and size, «Смени» / «Качи нова» / «Махни» in one row, the hint under it. Empty: a
     dashed box with the text and the two buttons. `multiple()`: a row of thumbnails with a small «×» each, «Добави от медията» / «Качи нови». «Махни» only changes the state: the file stays in «Медия». --}}
@php
    $statePath = $getStatePath();
    $assets = $getAssets();
    $ids = $getIds();
    $multiple = $isMultiple();
@endphp
@once
    @vite('resources/css/media-picker.css')
@endonce
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="mpf" x-data>
        @if ($ids === [])
            <div class="mpf-empty">
                <span class="mpf-none">{{ __('media.picker.none') }}</span>
                <span class="mpf-actions">{{ $getAction('pick') }} {{ $getAction('upload') }}</span>
            </div>
        @elseif (! $multiple)
            @php
                $asset = $assets[$ids[0]] ?? null;
                $thumb = $asset === null ? null : \App\Filament\Resources\MediaResource::previewUrl($asset, 'thumbnail');
            @endphp
            <div class="mpf-one">
                <div class="mpf-thumb">
                    @if ($thumb !== null)
                        <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" width="96" height="96" decoding="async">
                    @else
                        <span class="mpf-wait">{{ $asset === null ? __('media.picker.missing') : __('media.library.state.processing') }}</span>
                    @endif
                </div>
                <div class="mpf-info">
                    <span class="mpf-name" title="{{ $asset?->original_name }}">{{ \Illuminate\Support\Str::limit((string) ($asset?->original_name ?: ($asset ? basename((string) $asset->path) : '#'.$ids[0])), 40) }}</span>
                    @if ($asset?->width && $asset?->height)
                        <span class="mpf-dim">{{ (int) $asset->width }}×{{ (int) $asset->height }}</span>
                    @endif
                    <span class="mpf-actions">
                        {{ $getAction('pick') }}
                        {{ $getAction('upload') }}
                        <button type="button" class="mpf-remove" x-on:click="$wire.set(@js($statePath), null)">{{ __('media.picker.remove') }}</button>
                    </span>
                </div>
            </div>
        @else
            <div class="mpf-many">
                @foreach ($ids as $id)
                    @php
                        $asset = $assets[$id] ?? null;
                        $thumb = $asset === null ? null : \App\Filament\Resources\MediaResource::previewUrl($asset, 'thumbnail');
                    @endphp
                    <div class="mpf-tile" wire:key="mpf-{{ $statePath }}-{{ $id }}">
                        <div class="mpf-thumb mpf-thumb-small">
                            @if ($thumb !== null)
                                <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" width="64" height="64" decoding="async">
                            @else
                                <span class="mpf-wait">{{ $asset === null ? __('media.picker.missing') : __('media.library.state.processing') }}</span>
                            @endif
                        </div>
                        <button type="button" class="mpf-x" aria-label="{{ __('media.picker.remove') }}" x-on:click="$wire.set(@js($statePath), @js(array_values(array_diff($ids, [$id]))))">×</button>
                    </div>
                @endforeach
            </div>
            <span class="mpf-actions">{{ $getAction('pick') }} {{ $getAction('upload') }}</span>
        @endif

        <p class="mpf-hint">{{ __('media.picker.hint') }}</p>
    </div>
</x-dynamic-component>
