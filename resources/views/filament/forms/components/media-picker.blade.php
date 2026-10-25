{{-- The media picker field (ML-2, ML-3): app/Filament/Forms/Components/MediaPicker.php. One look for every place (brand logo, HTML block pictures, the Design media kind, product and variation photos).
     A single field, empty: a dashed box with ONE button «Добави изображение». Filled: the picture LARGE (full width of the card, whole), under it «Смени» and the quiet red link «Премахни изображението»
     (its title says the picture stays in «Медия»). A list: the thumbnails in a wrapping grid, reordered by drag and drop (Filament's own x-sortable, SortableJS) — the new order goes to the state as ids and is
     validated again on save; ← → appear only while a thumbnail has keyboard focus; each thumbnail has a small «×»; under the grid ONE button «Добави изображения». Removing only changes the state: the file
     stays in «Медия». --}}
@php
    $statePath = $getStatePath();
    $assets = $getAssets();
    $ids = $getIds();
    $multiple = $isMultiple();
    $isVideo = $isVideo();
    $removeLabel = __($isVideo ? 'media.picker.remove_video' : 'media.picker.remove_image');
@endphp
@once
    @vite('resources/css/media-picker.css')
@endonce
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="mpf" x-data>
        @if ($ids === [])
            <div class="mpf-empty">
                <span class="mpf-none">{{ __($multiple ? 'media.picker.none_many' : ($isVideo ? 'media.picker.none_video' : 'media.picker.none')) }}</span>
                <span class="mpf-actions">{{ $getAction('pick') }}</span>
            </div>
        @elseif (! $multiple)
            @php
                $asset = $assets[$ids[0]] ?? null;
                $thumb = $asset === null || $isVideo ? null : \App\Filament\Resources\MediaResource::previewUrl($asset, 'medium');
                $videoUrl = $asset === null || ! $isVideo ? null : app(\EasyCo\Media\Contracts\MediaStorageAdapter::class)->url((string) $asset->disk, (string) $asset->path);
            @endphp
            <div class="mpf-one">
                <div class="mpf-big">
                    @if ($videoUrl !== null)
                        <video src="{{ $videoUrl }}" controls preload="metadata" muted></video>
                    @elseif ($thumb !== null)
                        <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" decoding="async">
                    @else
                        <span class="mpf-wait">{{ $asset === null ? __('media.picker.missing') : __('media.library.state.processing') }}</span>
                    @endif
                </div>
                <span class="mpf-actions">
                    {{ $getAction('pick') }}
                    <button type="button" class="mpf-remove" title="{{ __('media.picker.hint') }}" x-on:click="$wire.set(@js($statePath), null)">{{ $removeLabel }}</button>
                </span>
            </div>
        @else
            <div class="mpf-many"
                 x-sortable
                 data-sortable-animation-duration="150"
                 x-on:end.stop="$wire.set(@js($statePath), $event.target.sortable.toArray().map(Number))">
                @foreach ($ids as $position => $id)
                    @php
                        $asset = $assets[$id] ?? null;
                        $thumb = $asset === null ? null : \App\Filament\Resources\MediaResource::previewUrl($asset, 'thumbnail');
                    @endphp
                    <div class="mpf-tile" wire:key="mpf-{{ $statePath }}-{{ $id }}" x-sortable-item="{{ $id }}">
                        <div class="mpf-thumb mpf-thumb-small" x-sortable-handle>
                            @if ($thumb !== null)
                                <img src="{{ $thumb }}" alt="{{ $asset->alt_text }}" width="64" height="64" decoding="async" draggable="false">
                            @else
                                <span class="mpf-wait">{{ $asset === null ? __('media.picker.missing') : __('media.library.state.processing') }}</span>
                            @endif
                        </div>
                        <button type="button" class="mpf-x" aria-label="{{ $removeLabel }}" title="{{ __('media.picker.hint') }}" x-on:click="$wire.set(@js($statePath), @js(array_values(array_diff($ids, [$id]))))">×</button>
                        <span class="mpf-order">
                            <button type="button" class="mpf-move" aria-label="{{ __('media.picker.move_left') }}" title="{{ __('media.picker.move_left') }}" @disabled($position === 0)
                                    x-on:click="$wire.set(@js($statePath), @js(\App\Media\PickerOrder::move($ids, $position, -1)))">←</button>
                            <button type="button" class="mpf-move" aria-label="{{ __('media.picker.move_right') }}" title="{{ __('media.picker.move_right') }}" @disabled($position === count($ids) - 1)
                                    x-on:click="$wire.set(@js($statePath), @js(\App\Media\PickerOrder::move($ids, $position, 1)))">→</button>
                        </span>
                    </div>
                @endforeach
            </div>
            <span class="mpf-actions">{{ $getAction('pick') }}</span>
        @endif
    </div>
</x-dynamic-component>
