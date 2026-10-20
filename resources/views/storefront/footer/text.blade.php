{{-- Footer block `text` (FT-1): an optional heading and a paragraph of PLAIN text; line breaks are kept (everything is escaped first). --}}
<div class="sf-ft-block sf-ft-text">
    @if ($settings['heading'] !== '')
        <p class="sf-ft-heading">{{ $settings['heading'] }}</p>
    @endif
    @if ($settings['body'] !== '')
        <p class="sf-ft-body">{!! nl2br(e($settings['body'])) !!}</p>
    @endif
</div>
