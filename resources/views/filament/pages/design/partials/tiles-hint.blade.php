{{-- HP-3: the recommended picture size of a «Плочки» section in the properties panel: the numbers are TileGeometry's (the one function the tests pin), re-rendered by Livewire when the columns, the gap or the shape
     change (no JavaScript). --}}
<div class="ds-gd">
    <p class="ds-gd-note"><b>{{ __('design.home.tiles.hint_title') }}</b></p>
    <p class="ds-gd-note">{{ __('design.home.tiles.hint_desktop', ['w' => $desktop['rounded_width'], 'h' => $desktop['rounded_height'], 'w2' => $desktop['double_width'], 'h2' => $desktop['double_height']]) }}</p>
    <p class="ds-gd-note">{{ __('design.home.tiles.hint_phone', ['w' => $phone['rounded_width'], 'h' => $phone['rounded_height'], 'w2' => $phone['double_width'], 'h2' => $phone['double_height']]) }}</p>
</div>
