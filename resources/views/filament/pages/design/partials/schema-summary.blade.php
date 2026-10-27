{{-- The «Дясна колона» schema (SC-1): ONE ordered list of components (the tree's one row and one cell stay hidden). A click selects a component; the selected one has ↑ ↓ and × (the locked ones refuse ×). --}}
@php
    $selected = $this->selected;
    $is = static fn (string $kind, string $id): bool => $selected !== null && $selected['kind'] === $kind && $selected['id'] === $id;
    $invalid = $this->invalid;
    $cell = $this->tree['main']['rows'][0]['cells'][0] ?? ['id' => '', 'items' => []];
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.zones.summary') }}</h2>
    <p class="ds-note">{{ __('design.summary.order_hint') }}</p>

    <ol class="ds-chips ds-chips-list">
        @foreach ($cell['items'] as $node)
            @php($on = $is('node', $node['id']))
            <li class="ds-chip @if ($on) ds-sel @endif @if (in_array($node['id'], $invalid, true)) ds-bad @endif" data-ds-component="{{ $node['type'] }}">
                <button type="button" class="ds-chip-main" aria-pressed="{{ $on ? 'true' : 'false' }}" wire:click="selectNode('{{ $node['id'] }}')">
                    {{ $this->typeLabel($node['type']) }}
                    @if (($summary = $this->summary($node)) !== '')<small>{{ $summary }}</small>@endif
                </button>
                @if ($on)
                    <span class="ds-moves">
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_up') }}" title="{{ __('design.actions.move_up') }}" wire:click="moveNode('{{ $node['id'] }}', 'up')" @disabled(! $this->canMove($node['id'], 'up'))>↑</button>
                        <button type="button" class="ds-icon-btn" aria-label="{{ __('design.actions.move_down') }}" title="{{ __('design.actions.move_down') }}" wire:click="moveNode('{{ $node['id'] }}', 'down')" @disabled(! $this->canMove($node['id'], 'down'))>↓</button>
                        <button type="button" class="ds-icon-btn ds-danger" aria-label="{{ __('design.actions.remove') }}" title="{{ __('design.actions.remove') }}" wire:click="removeNode('{{ $node['id'] }}')">×</button>
                    </span>
                @endif
            </li>
        @endforeach
    </ol>
</section>
