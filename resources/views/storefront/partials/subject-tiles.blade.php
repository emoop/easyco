{{-- SEO-1.1: the tiles of an index page (`/brand`, `/collection`): a picture (our rendition, the image partial; alt empty, the caption names the link) or, for a collection with no
     usable picture, the storefront's neutral placeholder, or nothing (a brand with no logo: a name-only tile). Names are plain text (escaped). No style, no script. --}}
<ul class="sf-subject-grid">
    @foreach ($tiles as $tile)
        <li>
            <a class="sf-subject-tile" href="{{ $tile->url }}">
                @if ($tile->image !== null)
                    <span class="sf-subject-pic">@include('storefront.partials.image', ['image' => $tile->image, 'eager' => false])</span>
                @elseif ($tile->placeholder)
                    <span class="sf-subject-pic sf-image-placeholder" aria-hidden="true"></span>
                @endif
                <span class="sf-subject-name">{{ $tile->name }}</span>
            </a>
        </li>
    @endforeach
</ul>
