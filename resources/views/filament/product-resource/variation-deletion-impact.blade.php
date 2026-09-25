{{--
    The body of the per-variation hard-delete confirmation —
    catalog-domain-design.md §3.19.8 A.

    Rendered through Action::modalContent() rather than modalDescription():
    the description is emitted as ONE escaped <p>, which cannot carry a
    labelled list of counts. Nothing here is computed beyond formatting —
    every number comes from App\Services\VariationDeletionImpact, and the
    refusal sentence is already translated by
    App\Services\VariationDeletionRefusalMessage before it arrives, so this
    view never builds a merchant-facing sentence itself.

    Deliberately unstyled: it inherits the panel's own typography, and no
    Tailwind class used only here is guaranteed to exist in the compiled
    panel CSS.
--}}
@if (! $impact->isDeletable())
    <div>
        <p><strong>{{ $refusalMessage }}</strong></p>

        {{-- §3.19.8 D: archiving is offered INSTEAD. It is the row's own
             archive control — already sitting in this row's action group —
             so this points at it rather than duplicating it. --}}
        <p>{{ __('products.deletion.archive_instead') }}</p>
    </div>
@else
    <div>
        <p>{{ __('products.deletion.impact_intro', ['product' => $impact->productName, 'sku' => $impact->sku]) }}</p>

        <ul>
            <li>{{ __('products.deletion.impact_attributes', ['attributes' => $attributesLabel]) }}</li>
            <li>{{ __('products.deletion.impact_stock', ['quantity' => $impact->stockQuantity]) }}</li>
            <li>{{ __('products.deletion.impact_sale_lines', ['count' => $impact->saleLineCount]) }}</li>
            <li>{{ __('products.deletion.impact_cart_lines', ['open' => $openCartLines, 'converted' => $impact->convertedCartLineCount]) }}</li>
            <li>{{ __('products.deletion.impact_price_list_items', ['count' => $impact->priceListItemCount]) }}</li>
            <li>{{ __('products.deletion.impact_cost_rows', ['count' => $impact->costRowCount]) }}</li>
            <li>{{ __('products.deletion.impact_media', ['count' => $impact->mediaCount]) }}</li>
        </ul>

        {{-- §3.19.8's own warning: the page reloads afterwards, so anything
             typed here but not saved is gone. Said BEFORE the decision, not
             after it. --}}
        <p>{{ __('products.deletion.unsaved_edits_warning') }}</p>
    </div>
@endif
