{{-- The modal of the media picker (ML-2): app/Livewire/MediaPickerBrowser.php. Two tabs, one search, one toggle, a grid of squares, a narrow column for the selected picture, a bottom bar with
     «Отказ» and «Избери». Every control is a real <button> or <input>; every printed value is escaped. --}}
<div class="mp">
    <div class="mp-tabs" role="tablist">
        <button type="button" role="tab" id="mp-tab-library" aria-selected="{{ $tab === 'library' ? 'true' : 'false' }}" class="mp-tab @if ($tab === 'library') mp-tab-on @endif" wire:click="showTab('library')">{{ __('media.picker.tab_library') }}</button>
        <button type="button" role="tab" id="mp-tab-upload" aria-selected="{{ $tab === 'upload' ? 'true' : 'false' }}" class="mp-tab @if ($tab === 'upload') mp-tab-on @endif" wire:click="showTab('upload')">{{ __('media.picker.tab_upload') }}</button>
    </div>

    @error('selected')
        <p class="mp-error" role="alert">{{ $message }}</p>
    @enderror

    @if ($tab === 'upload')
        <div class="mp-upload" role="tabpanel" aria-labelledby="mp-tab-upload">
            @if ($refused !== [])
                <ul class="mp-refused" role="alert">
                    @foreach ($refused as $item)
                        <li>{{ $item['name'] }} — {{ $item['reason'] }}</li>
                    @endforeach
                </ul>
            @endif

            <label class="mp-drop" x-data="{ over: false }" x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop="over = false" x-bind:class="{ 'mp-drop-over': over }">
                <span>{{ __('media.picker.drop') }}</span>
                <small>{{ $kind === 'video' ? __('media.picker.drop_help_video') : ($mimes !== null ? ucfirst(__('media.picker.mime_only', ['types' => \App\Media\MediaUploader::typeNames($mimes)])).'. '.__('media.picker.drop_help_max', ['max' => $maxFiles]) : __('media.picker.drop_help', ['max' => $maxFiles])) }}@if ($minWidth) {{ __('media.picker.drop_help_min', ['w' => $minWidth, 'h' => $minHeight]) }}@endif</small>
                <input type="file" class="mp-file" @if ($kind !== 'video') multiple @endif accept="{{ $accept }}" wire:model="files">
                <span wire:loading wire:target="files" class="mp-busy">{{ __('media.picker.uploading') }}</span>
            </label>
        </div>
    @else
        <div class="mp-library" role="tabpanel" aria-labelledby="mp-tab-library">
            <div class="mp-tools">
                <input type="search" class="mp-search" placeholder="{{ __('media.picker.search') }}" aria-label="{{ __('media.picker.search') }}" wire:model.live.debounce.400ms="search" autocomplete="off">
                <label class="mp-toggle"><input type="checkbox" wire:model.live="onlyUnused"> {{ __('media.picker.only_unused') }}</label>
            </div>

            <div class="mp-body">
                <div class="mp-grid-wrap">
                    @if ($rows->isEmpty())
                        <p class="mp-empty">{{ __('media.picker.empty') }}</p>
                    @else
                        <div class="mp-grid" role="group" aria-label="{{ __('media.picker.title') }}">
                            @foreach ($rows as $row)
                                @php
                                    $on = in_array((int) $row->id, $selected, true);
                                    $thumb = \App\Filament\Resources\MediaResource::previewUrl($row, 'thumbnail');
                                    $name = (string) ($row->original_name ?: basename((string) $row->path));
                                @endphp
                                <button type="button" wire:key="mp-{{ $row->id }}" class="mp-card @if ($on) mp-card-on @endif" aria-pressed="{{ $on ? 'true' : 'false' }}" title="{{ $name }}"
                                        wire:click="toggle({{ (int) $row->id }})" wire:dblclick="choose({{ (int) $row->id }})">
                                    @if ($kind === 'video')
                                        <span class="mp-wait">▶ {{ \Illuminate\Support\Str::limit($name, 28) }}</span>
                                    @elseif ($thumb !== null)
                                        <img src="{{ $thumb }}" alt="{{ $row->alt_text }}" loading="lazy" decoding="async" width="150" height="150">
                                    @else
                                        <span class="mp-wait">{{ __('media.library.state.processing') }}</span>
                                    @endif
                                    @if ($minWidth !== null && ($row->width === null || $row->height === null))
                                        <span class="mp-note">{{ __('media.picker.unknown_size') }}</span>
                                    @endif
                                    @if ($mimes !== null && $row->mime === null)
                                        <span class="mp-note">{{ __('media.picker.unknown_type') }}</span>
                                    @endif
                                    @if ($on)
                                        <span class="mp-check" aria-hidden="true">✓</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($hasMore)
                            <div class="mp-more"><button type="button" class="mp-btn" wire:click="more">{{ __('media.picker.more') }}</button></div>
                        @endif
                    @endif
                </div>

                @if ($current !== null)
                    <aside class="mp-side" aria-label="{{ __('media.picker.selected') }}">
                        <div class="mp-preview">
                            @if ($currentVideoUrl !== null)
                                <video src="{{ $currentVideoUrl }}" controls preload="metadata" muted></video>
                            @elseif ($currentPreview !== null)
                                <img src="{{ $currentPreview }}" alt="{{ $current->alt_text }}" decoding="async">
                            @else
                                <span class="mp-wait">{{ __('media.library.state.processing') }}</span>
                            @endif
                        </div>
                        <p class="mp-name">{{ $current->original_name ?: basename((string) $current->path) }}</p>
                        <p class="mp-muted">
                            @if ($current->width && $current->height){{ (int) $current->width }}×{{ (int) $current->height }}@endif
                            @if ($current->size_bytes) · {{ $current->size_bytes >= 1048576 ? number_format($current->size_bytes / 1048576, 2).' MB' : number_format($current->size_bytes / 1024, 1).' KB' }}@endif
                        </p>

                        @if (! $multiple && $kind !== 'video')
                            <label class="mp-label" for="mp-alt">{{ __('media.library.details.alt') }}</label>
                            <input type="text" id="mp-alt" class="mp-input" maxlength="200" wire:model="alt" autocomplete="off">
                            @error('alt')<p class="mp-error" role="alert">{{ $message }}</p>@enderror
                        @endif

                        @if ($withSize)
                            <fieldset class="mp-size">
                                <legend class="mp-label">{{ __('media.picker.size') }}</legend>
                                <label><input type="radio" value="medium" wire:model="size"> {{ __('media.picker.size_medium') }}</label>
                                <label><input type="radio" value="large" wire:model="size"> {{ __('media.picker.size_large') }}</label>
                            </fieldset>
                        @endif
                    </aside>
                @endif
            </div>
        </div>
    @endif

    <div class="mp-bar">
        <button type="button" class="mp-btn mp-btn-plain" x-on:click="$dispatch('media-picker-cancel')">{{ __('media.picker.cancel') }}</button>
        <button type="button" class="mp-btn mp-btn-primary" wire:click="confirm" @disabled($selected === [])>{{ $multiple && count($selected) > 0 ? __('media.picker.choose_n', ['n' => count($selected)]) : __('media.picker.choose') }}</button>
    </div>
</div>
