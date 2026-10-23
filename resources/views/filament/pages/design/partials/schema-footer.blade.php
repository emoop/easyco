{{-- The «Футър» schema (DS-2b): the UPPER level (rows of columns of block chips; rows and columns are added, removed and moved here) and the LOWER level (one line). --}}
@php
    $selected = $this->selected;
    $is = static fn (string $kind, string $id): bool => $selected !== null && $selected['kind'] === $kind && $selected['id'] === $id;
    $invalid = $this->invalid;
    $upper = $this->tree['upper'] ?? [];
    $lower = $this->tree['lower'] ?? [];
    $spec = \App\Storefront\Design\ZoneSpec::for('footer.upper');
    $line = $lower['rows'][0]['cells'][0]['items'][0] ?? null;
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.schema.title') }}</h2>

    <div class="ds-level @if ($is('layout', 'upper')) ds-sel @endif @if (in_array('upper', $invalid, true)) ds-bad @endif">
        <button type="button" class="ds-layout-head" aria-pressed="{{ $is('layout', 'upper') ? 'true' : 'false' }}" wire:click="selectLayout('upper')">
            {{ __('footer.sections.upper') }}
            @unless ($upper['props']['enabled'] ?? false)<span class="ds-tag">{{ __('design.schema.disabled') }}</span>@endunless
        </button>

        @foreach ($upper['rows'] ?? [] as $rowIndex => $row)
            <div class="ds-row @if ($is('row', $row['id'])) ds-sel @endif @if (in_array($row['id'], $invalid, true)) ds-bad @endif">
                <div class="ds-row-head">
                    <button type="button" class="ds-row-title" aria-pressed="{{ $is('row', $row['id']) ? 'true' : 'false' }}" wire:click="selectRow('{{ $row['id'] }}')">{{ __('footer.fields.row') }} {{ $rowIndex + 1 }}</button>
                    @if (count($upper['rows']) > $spec->minRows)
                        <button type="button" class="ds-icon-btn ds-danger" aria-label="{{ __('design.actions.remove_row') }}" title="{{ __('design.actions.remove_row') }}"
                            @if (collect($row['cells'])->sum(fn ($c) => count($c['items'])) > 0) wire:click="mountAction('removeRow', { id: '{{ $row['id'] }}' })" @else wire:click="removeRow('{{ $row['id'] }}')" @endif>×</button>
                    @endif
                </div>
                <div class="ds-cells ds-cells-footer">
                    @foreach ($row['cells'] as $cell)
                        <div class="ds-cell @if ($is('cell', $cell['id'])) ds-sel @endif @if (in_array($cell['id'], $invalid, true)) ds-bad @endif">
                            <div class="ds-cell-head">
                                <button type="button" class="ds-cell-title" aria-pressed="{{ $is('cell', $cell['id']) ? 'true' : 'false' }}" wire:click="selectCell('{{ $cell['id'] }}')">{{ __('footer.fields.column') }} {{ $loop->iteration }}</button>
                                <button type="button" class="ds-icon-btn" aria-label="{{ __('design.schema.add_here') }}" title="{{ __('design.schema.add_here') }}" wire:click="selectCell('{{ $cell['id'] }}')">+</button>
                            </div>
                            <div class="ds-moves">
                                <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_column_left') }}" title="{{ __('design.actions.move_column_left') }}" wire:click="moveColumn('{{ $cell['id'] }}', 'left')" @disabled(! $this->canMoveColumn($cell['id'], 'left'))>←</button>
                                <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_column_right') }}" title="{{ __('design.actions.move_column_right') }}" wire:click="moveColumn('{{ $cell['id'] }}', 'right')" @disabled(! $this->canMoveColumn($cell['id'], 'right'))>→</button>
                                @if (count($row['cells']) > $spec->minCells)
                                    <button type="button" class="ds-icon-btn ds-danger" aria-label="{{ __('design.actions.remove_column') }}" title="{{ __('design.actions.remove_column') }}"
                                        @if ($cell['items'] !== []) wire:click="mountAction('removeColumn', { id: '{{ $cell['id'] }}' })" @else wire:click="removeColumn('{{ $cell['id'] }}')" @endif>×</button>
                                @endif
                            </div>
                            @include('filament.pages.design.partials.chips', ['cell' => $cell])
                        </div>
                    @endforeach
                </div>
                @if (count($row['cells']) < $spec->maxCells)
                    <button type="button" class="ds-btn" wire:click="addColumn('{{ $row['id'] }}')">+ {{ __('design.actions.add_column') }}</button>
                @endif
            </div>
        @endforeach

        @if (count($upper['rows'] ?? []) < $spec->maxRows)
            <button type="button" class="ds-btn" wire:click="addRow">+ {{ __('design.actions.add_row') }}</button>
        @endif
    </div>

    <div class="ds-level @if ($is('layout', 'lower')) ds-sel @endif @if (in_array('lower', $invalid, true)) ds-bad @endif">
        <button type="button" class="ds-layout-head" aria-pressed="{{ $is('layout', 'lower') ? 'true' : 'false' }}" wire:click="selectLayout('lower')">
            {{ __('footer.sections.lower') }}
            @unless ($lower['props']['enabled'] ?? false)<span class="ds-tag">{{ __('design.schema.disabled') }}</span>@endunless
        </button>
        <p class="ds-line">{{ $line['settings']['text_template'] ?? '' }}</p>
    </div>
</section>
