{{-- NF-1: «Съдържание отдолу» of the 404 page: the home page's own sections (tiles, a product grid or an HTML block), printed through the home partials. $sections = HomeView::prepare()/fill() result.
     No inline style, no script. Cached as a fragment (NotFoundFragment). --}}
<div class="sf-hp sf-hp-gap-{{ $sections['gap'] }}">
    @foreach ($sections['items'] as $item)
        <section class="{{ $item['class'] }}">
            @include($item['view'], ['item' => $item])
        </section>
    @endforeach
</div>
