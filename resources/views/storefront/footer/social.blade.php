{{-- Footer block `social` (FT-1): the header's closed set of network icons; every address is https:// (validated on save, publish and read). --}}
<ul class="sf-hdr-social sf-ft-block sf-ft-social" aria-label="{{ __('header.social_label') }}">
    @foreach ($settings['items'] as $item)
        <li>
            <a class="sf-hdr-social-link" href="{{ $item['url'] }}" rel="noopener noreferrer">
                @include('storefront.header.social-icon', ['network' => $item['network']])
                <span class="sf-sr-only">{{ __('header.social.'.$item['network']) }}</span>
            </a>
        </li>
    @endforeach
</ul>
