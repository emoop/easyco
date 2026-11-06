@if (($below ?? null) !== null)
    {{-- P4: the slots below the main area of the product page («Дизайн» → «Под галерията»): each slot is the listing pages' slot partial (the HOME page's own markup: sections, partials and classes from closed lists, prepared by
         BelowView) with its optional heading with lines. Nothing is printed without a slot (this file's first line is flush-left for that), so a page with no slot is byte-identical to the page it always was. --}}
    <div class="sf-below">
        @foreach ($below as $one)
            @include('storefront.partials.listing-slot', ['slot' => $one, 'where' => 'bottom', 'heading' => $one['heading']])
        @endforeach
    </div>
@endif
