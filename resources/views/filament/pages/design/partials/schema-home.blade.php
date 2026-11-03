{{-- The «Начална» schema (HP-1): the home page as ONE ordered list of sections (the tree's one row and one cell stay hidden). The «Общи настройки» button selects the layout (the spacing between the sections); a click on a
     section selects it; the selected one has ↑ ↓ and × (a hidden section stays in the list). --}}
@php
    $selected = $this->selected;
    $is = static fn (string $kind, string $id): bool => $selected !== null && $selected['kind'] === $kind && $selected['id'] === $id;
    $invalid = $this->invalid;
    $cell = $this->tree['main']['rows'][0]['cells'][0] ?? ['id' => '', 'items' => []];
    $gap = (string) ($this->tree['main']['props']['spacing'] ?? \App\Storefront\Home\HomeCatalog::DEFAULT_SPACING);
@endphp
<section class="ds-schema" aria-labelledby="ds-schema-title">
    <h2 class="ds-h" id="ds-schema-title">{{ __('design.zones.home') }}</h2>
    <p class="ds-note">{{ __('design.home.order_hint') }}</p>

    <button type="button" class="ds-layout-head @if ($is('layout', 'main')) ds-sel @endif @if (in_array('main', $invalid, true)) ds-bad @endif" aria-pressed="{{ $is('layout', 'main') ? 'true' : 'false' }}" wire:click="selectLayout('main')">
        {{ __('design.home.general') }} <small>{{ __('design.home.gap_now', ['px' => $gap]) }}</small>
    </button>

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
