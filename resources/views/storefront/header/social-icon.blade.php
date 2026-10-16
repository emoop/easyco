{{-- A CLOSED set of small outline glyphs (inline SVG, decorative, plain paths): one per network of HeaderCatalog::SOCIAL_NETWORKS. --}}
<svg class="sf-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($network)
        @case('facebook') <path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8z"/> @break
        @case('instagram') <path d="M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4z"/><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z"/><path d="M17.5 6.5h.01"/> @break
        @case('tiktok') <path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.3 2.4 1.8 4 4.5 4.2"/> @break
        @case('youtube') <path d="M4 7h16a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z"/><path d="M10 9.5v5l4.5-2.5-4.5-2.5z"/> @break
        @case('pinterest') <path d="M12 3a8 8 0 0 0-2.9 15.4"/><path d="M9.5 21l2-8"/><path d="M11 13.5c2.6 1 5-.8 5-3.5a3.5 3.5 0 0 0-6.5-1.5"/> @break
        @case('viber') <path d="M5 4h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-5l-4 4v-4H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/><path d="M9 8.5c.5 2 2.5 4 5 5"/> @break
        @case('whatsapp') <path d="M4 20l1.3-4.3A8 8 0 1 1 8.4 18.7L4 20z"/><path d="M9 9c.5 2.5 2.5 4.5 6 6"/> @break
        @case('x') <path d="M5 4l14 16"/><path d="M19 4L5 20"/> @break
    @endswitch
</svg>
