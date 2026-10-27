{{-- DS-5: the state indicator beside «Публикувай»: a small text, not a button. `panel` is client-side first (Alpine reads the page's `panelDirty`, which the panel's fields set the moment they are touched);
     the other two come from the server: the draft equals the published version (or there is none), or it does not. --}}
<span class="ds-state" data-ds-state="{{ $screen->stateKey() }}" role="status">
    <span class="ds-state-panel" x-show="$wire.panelDirty" x-cloak>{{ __('design.status.panel') }}</span>
    <span class="ds-state-{{ $screen->stateKey() === 'draft' ? 'draft' : 'published' }}" x-show="! $wire.panelDirty" x-cloak>{{ __('design.status.'.($screen->stateKey() === 'draft' ? 'draft' : 'published')) }}</span>
</span>
