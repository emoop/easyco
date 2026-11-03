{{-- HB-5: the live preview of a «Снимка с текст» block in the block's form. The markup and the classes are the shop's (HtmlBlockCard::classes / html); the storefront's CSS is NOT loaded on an admin page, so the look is the inline
     declarations HtmlBlockCard::preview worked out from the VALID values of the form: the ONLY inline styles of the feature, on an ADMIN page only; the storefront never prints one. --}}
<div class="fi-hb-preview" style="padding: 0.5rem 0;">
    <div class="{{ $p['classes'] }}" style="{{ $p['box'] }}" @if ($p['role'] !== null) role="img" aria-label="{{ $p['lines']['title'] }}" @endif>
        <div style="{{ $p['veil'] }}"></div>
        @if ($p['lines']['title'] !== '' || $p['lines']['subtitle'] !== '' || $p['lines']['text'] !== '')
            <div class="sf-pc-text" style="{{ $p['text'] }}">
                @if ($p['lines']['title'] !== '')<div class="sf-pc-title" style="{{ $p['title'] }}">{{ $p['lines']['title'] }}</div>@endif
                @if ($p['lines']['subtitle'] !== '')<div class="sf-pc-sub" style="{{ $p['sub'] }}">{{ $p['lines']['subtitle'] }}</div>@endif
                @if ($p['lines']['text'] !== '')<div class="sf-pc-par" style="{{ $p['par'] }}">{!! nl2br(e($p['lines']['text'])) !!}</div>@endif
            </div>
        @endif
    </div>
    @if ($p['link'])
        <div style="margin-top: 0.25rem; font-size: 0.75rem; opacity: 0.7;">{{ __('html_blocks.card.preview_link') }}</div>
    @endif
</div>
