{{-- HP-4, home section `product_grid`: the first N products of a source, printed through the SAME card partial as the category listing (read at request time by HomeView::fill; a grid with no product is not a
     section at all). Every class is from a closed list (columns, phone columns, gap); no inline style, no script, no pagination. $item['products'] = {class, cards[ProductCard], sizes, ...}. --}}
<div class="{{ $item['products']['class'] }}">
    @foreach ($item['products']['cards'] as $card)
        @include('storefront.partials.card', ['card' => $card, 'eager' => $loop->index < 2])
    @endforeach
</div>
