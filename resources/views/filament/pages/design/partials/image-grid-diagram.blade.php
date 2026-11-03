{{-- HP-2: the size diagram of an image grid in the properties panel: the cells of the chosen scheme, each labelled with its letter and the recommended picture size, from ImageGridGeometry (the one function the tests pin);
     it is re-rendered by Livewire when the scheme, the proportion or the gap changes (no JavaScript). Classes from the design-screen stylesheet, no inline style. --}}
<div class="ds-gd">
    <div class="ds-gd-box ds-gd-s-{{ $scheme }} ds-gd-r-{{ str_replace(':', '-', $ratio) }} ds-gd-g-{{ $gap }}" role="img" aria-label="{{ __('design.home.grid.sizes_title') }}">
        @foreach ($cells as $cell)
            <div class="ds-gd-cell ds-gd-a-{{ $cell['name'] }}">
                <b>{{ strtoupper($cell['name']) }}</b>
                <span>{{ $cell['rounded_width'] }} × {{ $cell['rounded_height'] }}</span>
                <em>{{ $cell['label'] }}</em>
            </div>
        @endforeach
    </div>
    <p class="ds-gd-note">{{ __('design.home.grid.sizes_note') }}</p>
    <p class="ds-gd-note">{{ __('design.home.grid.sizes_kind') }}</p>
</div>
