@if ($settings['label'] !== '' && $settings['url'] !== '')
    <a class="sf-hdr-button sf-hdr-button-{{ $settings['style'] }}" href="{{ $settings['url'] }}">{{ $settings['label'] }}</a>
@endif
