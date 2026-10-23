<div class="ds-cell @if ($is('cell', $cell['id'])) ds-sel @endif @if (in_array($cell['id'], $invalid, true)) ds-bad @endif">
    <div class="ds-cell-head">
        <button type="button" class="ds-cell-title" aria-pressed="{{ $is('cell', $cell['id']) ? 'true' : 'false' }}" wire:click="selectCell('{{ $cell['id'] }}')">{{ $this->cellName($cell) }}</button>
        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.schema.add_here') }}" title="{{ __('design.schema.add_here') }}" wire:click="selectCell('{{ $cell['id'] }}')">+</button>
    </div>
    @include('filament.pages.design.partials.chips', ['cell' => $cell])
</div>
