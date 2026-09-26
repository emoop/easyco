{{--
    The body of the ARCHIVED-product hard-delete confirmation —
    catalog-domain-design.md §3.19.8 B.

    Rendered through Action::modalContent(), like the per-variation modal and
    for the same reason: the description is emitted as ONE escaped <p>, which
    cannot carry a labelled list of counts, and this body IS a list.

    Nothing here is computed beyond formatting: every number comes from
    App\Services\ProductDeletionImpact, every per-variation line is that
    variation's own VariationDeletionImpact (so its verdict cannot disagree
    with what deleteProduct() will do), and the refusal sentence arrives
    already translated from App\Services\ProductDeletionRefusalMessage.

    The variation list is deliberately shown in BOTH branches. When the
    operation is refused the refusal sentence names the blocking variations,
    and the list shows every one's own verdict — including the ones that are
    perfectly deletable, so the merchant sees exactly what the two-step rule
    (G-D3: every variation must pass, or nothing goes) is waiting on.

    Deliberately unstyled, same as the variation modal: it inherits the
    panel's own typography.
--}}
@if (! $impact->isDeletable())
    <div>
        <p><strong>{{ $refusalMessage }}</strong></p>

        {{-- §3.19.8 D: archiving is offered INSTEAD, and for a product that
             is the reversible operation the merchant already has. --}}
        <p>{{ __('products.deletion.product_archive_instead') }}</p>
    </div>
@else
    <div>
        <p>{{ __('products.deletion.product_impact_intro', ['product' => $impact->productName, 'sku' => $impact->baseSku]) }}</p>
        <p>{{ __('products.deletion.product_impact_identifiers', ['base_sku' => $impact->baseSku, 'slug' => $impact->slug]) }}</p>
    </div>
@endif

<div>
    <p>{{ __('products.deletion.product_impact_variations', ['count' => $impact->variationCount()]) }}</p>

    <ul>
        @foreach ($impact->variations as $variation)
            <li>{{ __('products.deletion.product_impact_variation', [
                'sku' => $variation->sku,
                'attributes' => $variationLabels[$variation->variationId] ?? __('products.deletion.product_impact_no_attributes'),
                'verdict' => $variation->isDeletable()
                    ? __('products.deletion.product_impact_will_delete')
                    : __('products.deletion.product_impact_will_archive'),
            ]) }}</li>
        @endforeach
    </ul>
</div>

@if ($impact->isDeletable())
    <div>
        <p>{{ __('products.deletion.product_impact_scopes', [
            'price_lists' => $impact->priceListScopeCount,
            'promotions' => $impact->promotionScopeCount,
        ]) }}</p>

        <ul>
            <li>{{ __('products.deletion.impact_cart_lines', ['open' => $openCartLines, 'converted' => $impact->convertedCartLineCount]) }}</li>
            {{-- The VARIATION part is the total minus the product-scope part:
                 one number is computed, never two that could disagree. --}}
            <li>{{ __('products.deletion.product_impact_price_list_items', [
                'total' => $impact->priceListItemCount,
                'variation' => $impact->priceListItemCount - $impact->productPriceListItemCount,
                'product' => $impact->productPriceListItemCount,
            ]) }}</li>
            <li>{{ __('products.deletion.impact_cost_rows', ['count' => $impact->costRowCount]) }}</li>
            {{-- §3.19.7/D6: the counts are real, the FILES are not touched —
                 said here, where the number is, not in a note elsewhere. --}}
            <li>{{ __('products.deletion.product_impact_media', [
                'total' => $impact->mediaCount,
                'variation' => $impact->mediaCount - $impact->productMediaCount,
                'product' => $impact->productMediaCount,
            ]) }}</li>
        </ul>

        <p>{{ __('products.deletion.product_impact_unrepeatable') }}</p>
        <p>{{ __('products.deletion.product_after_delete_note') }}</p>
    </div>
@endif
