@if (($slot ?? null) !== null)
    {{-- P3: a slot of a listing page's template (the block above the list, the block or carousel below it). It is the HOME page's own markup: the same sections, partials and classes (`sf-hp`, `sf-car`), prepared by
         HomeView from the compiled copy, so the HTML is sanitised and every class is from a closed list. $slot = {gap, items}, $where = top | bottom. Nothing is printed without a slot (this file's first line is flush-left for that). --}}
    <div class="sf-hp sf-hp-gap-{{ $slot['gap'] }} sf-lp-slot sf-lp-slot-{{ $where }}">
        @foreach ($slot['items'] as $item)
            <section class="{{ $item['class'] }}">
                @include($item['view'], ['item' => $item])
            </section>
        @endforeach
    </div>
@endif