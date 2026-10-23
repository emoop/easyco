@php
    $selected = $this->selected;
    $is = static fn (string $kind, string $id): bool => $selected !== null && $selected['kind'] === $kind && $selected['id'] === $id;
    $invalid = $this->invalid;
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>

    <button type="button" class="ds-layout-head @if ($is('layout', $this->device)) ds-sel @endif @if (in_array($this->device, $invalid, true)) ds-bad @endif" aria-pressed="{{ $is('layout', $this->device) ? 'true' : 'false' }}" wire:click="selectLayout">{{ __('design.schema.layout_settings') }} — {{ __('design.devices.'.$this->device) }}</button>

    @foreach ($this->rows() as $row)
        @php($enabled = (bool) ($row['props']['enabled'] ?? false))
        <div class="ds-row @unless ($enabled) ds-off @endunless @if ($is('row', $row['id'])) ds-sel @endif @if (in_array($row['id'], $invalid, true)) ds-bad @endif">
            <button type="button" class="ds-row-title" aria-pressed="{{ $is('row', $row['id']) ? 'true' : 'false' }}" wire:click="selectRow('{{ $row['id'] }}')">
                {{ $this->rowName($row) }}
                @unless ($enabled)<span class="ds-tag">{{ __('design.schema.disabled') }}</span>@endunless
            </button>
            <div class="ds-cells">
                @foreach ($row['cells'] as $cell)
                    @include('filament.pages.design.partials.cell', ['cell' => $cell])
                @endforeach
            </div>
        </div>
    @endforeach

    @if (($drawer = $this->drawerCell()) !== null)
        <div class="ds-row ds-drawer">
            <button type="button" class="ds-row-title" aria-pressed="{{ $is('layout', $this->device) ? 'true' : 'false' }}" wire:click="selectLayout">{{ __('design.schema.drawer_settings') }}</button>
            <p class="ds-note">{{ __('design.schema.drawer_note') }}</p>
            <div class="ds-cells ds-cells-one">
                @include('filament.pages.design.partials.cell', ['cell' => $drawer])
            </div>
        </div>
    @endif
</section>
