@if ($settings['text'] !== '')
    @if ($settings['url'] !== '')
        <a class="sf-hdr-text sf-hdr-text-link" href="{{ $settings['url'] }}">{{ $settings['text'] }}</a>
    @else
        <span class="sf-hdr-text">{{ $settings['text'] }}</span>
    @endif
@endif
