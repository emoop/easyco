@if ($settings['items'] !== [])
    <ul class="sf-hdr-social" aria-label="{{ __('header.social_label') }}">
        @foreach ($settings['items'] as $item)
            <li>
                <a class="sf-hdr-social-link" href="{{ $item['url'] }}" rel="noopener noreferrer">
                    @include('storefront.header.social-icon', ['network' => $item['network']])
                    <span class="sf-sr-only">{{ __('header.social.'.$item['network']) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
