<div class="ds-cell @if ($is('cell', $cell['id'])) ds-sel @endif @if (in_array($cell['id'], $invalid, true)) ds-bad @endif">
    <div class="ds-cell-head">
        <button type="button" class="ds-cell-title" aria-pressed="{{ $is('cell', $cell['id']) ? 'true' : 'false' }}" wire:click="selectCell('{{ $cell['id'] }}')">{{ $this->cellName($cell) }}</button>
        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.schema.add_here') }}" title="{{ __('design.schema.add_here') }}" wire:click="selectCell('{{ $cell['id'] }}')">+</button>
    </div>
    <ul class="ds-chips">
        @forelse ($cell['items'] as $node)
            @php($on = $is('node', $node['id']))
            <li class="ds-chip @if ($on) ds-sel @endif @if (in_array($node['id'], $invalid, true)) ds-bad @endif">
                <button type="button" class="ds-chip-main" aria-pressed="{{ $on ? 'true' : 'false' }}" wire:click="selectNode('{{ $node['id'] }}')">
                    {{ $this->typeLabel($node['type']) }}
                    @if (($summary = $this->summary($node)) !== '')<small>{{ $summary }}</small>@endif
                </button>
                @if ($on)
                    <span class="ds-moves">
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_left') }}" title="{{ __('design.actions.move_left') }}" wire:click="moveNode('{{ $node['id'] }}', 'left')" @disabled(! $this->canMove($node['id'], 'left'))>←</button>
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_right') }}" title="{{ __('design.actions.move_right') }}" wire:click="moveNode('{{ $node['id'] }}', 'right')" @disabled(! $this->canMove($node['id'], 'right'))>→</button>
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_up') }}" title="{{ __('design.actions.move_up') }}" wire:click="moveNode('{{ $node['id'] }}', 'up')" @disabled(! $this->canMove($node['id'], 'up'))>↑</button>
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_down') }}" title="{{ __('design.actions.move_down') }}" wire:click="moveNode('{{ $node['id'] }}', 'down')" @disabled(! $this->canMove($node['id'], 'down'))>↓</button>
                        <button type="button" class="ds-icon-btn ds-danger" aria-label="{{ __('design.actions.remove') }}" title="{{ __('design.actions.remove') }}" @if ($node['type'] === 'logo') wire:click="mountAction('removeNode', { id: '{{ $node['id'] }}' })" @else wire:click="removeNode('{{ $node['id'] }}')" @endif>×</button>
                    </span>
                @endif
            </li>
        @empty
            <li class="ds-empty">{{ __('design.schema.empty') }}</li>
        @endforelse
    </ul>
</div>
