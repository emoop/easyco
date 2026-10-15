{{-- The top banner (storefront-customization-design.md 3.3): ABOVE the header, escaped text only, one line that ends in an ellipsis on a phone. Its colours come from
     the compiled token stylesheet (--sf-banner-text / --sf-banner-bg), never from a style attribute. With a link and no link text the WHOLE bar is the link; with a
     link text the text stays plain and the link is its own word. The full text stays in the page (the link's accessible name is the whole sentence). --}}
@if ($banner !== null)
    <div class="sf-banner" data-sf-banner>
        @if ($banner['url'] !== null && $banner['label'] === null)
            <a class="sf-banner-inner sf-banner-bar-link" href="{{ $banner['url'] }}"><span class="sf-banner-text">{{ $banner['text'] }}</span></a>
        @else
            <p class="sf-banner-inner">
                <span class="sf-banner-text">{{ $banner['text'] }}</span>
                @if ($banner['url'] !== null)
                    <a class="sf-banner-link" href="{{ $banner['url'] }}">{{ $banner['label'] }}</a>
                @endif
            </p>
        @endif
    </div>
@endif
