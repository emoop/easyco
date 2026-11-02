{{-- HINT-1: the explanation of a field as an info icon next to its label. The text stays in the page: the icon is a focusable button (accessible name, never submits a form) described by the bubble (role="tooltip",
     the id is in aria-describedby). CSS shows the bubble on hover and on focus; Alpine (already part of Filament, no library added) adds the tap toggle (a button does not take focus on every touch screen), the close
     on a tap elsewhere and on Esc. $text is escaped; $id links the icon and the bubble. --}}
@props(['text', 'id'])
<span class="info-hint" x-data="{ open: false }" x-bind:data-open="open ? 'true' : 'false'" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false; $refs.btn.blur()">
    <button type="button" class="info-hint-btn" x-ref="btn" x-on:click.prevent.stop="open = ! open" aria-label="{{ __('design.hint.info') }}" aria-describedby="{{ $id }}">
        <svg class="info-hint-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
    </button>
    <span class="info-hint-bubble" role="tooltip" id="{{ $id }}">{{ $text }}</span>
</span>
