<section class="ds-palette" aria-labelledby="ds-palette-title">
    <h2 class="ds-h" id="ds-palette-title">{{ __('design.palette.title') }}</h2>
    <p class="ds-note">{{ $zoneObject instanceof \App\Filament\Design\Zones\HasOneCell ? __('design.summary.palette_hint') : __('design.palette.hint') }}</p>
    <div class="ds-palette-chips">
        @foreach ($this->palette() as $item)
            <button type="button" class="ds-chip-add" wire:click="addNode('{{ $item['type'] }}')" @if ($item['reason'] !== null) disabled title="{{ $item['reason'] }}" @endif>{{ $item['label'] }}@if ($item['hint'] !== null) <small>({{ $item['hint'] }})</small>@endif</button>
        @endforeach
    </div>
</section>
