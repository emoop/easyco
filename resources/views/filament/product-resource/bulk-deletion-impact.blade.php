{{--
    The body of the BULK delete confirmation — the products list's Archived view
    (D6).

    Rendered through Action::modalContent(), like the per-row product delete and
    the per-variation modal and for the same reason: the description is emitted as
    ONE escaped <p>, which cannot carry a per-product list, and this body IS one.

    Nothing here is computed beyond formatting: the counts come from the action's
    own factory (the same memoized App\Services\ProductDeletionImpact set the
    submit button and the confirmation rule read), and every refusal sentence
    arrives already translated from App\Services\ProductDeletionRefusalMessage —
    the SAME sentence the single-product modal shows.

    THE ROWS ARE SHOWN IN BOTH BRANCHES of a selection: the ones that will be
    deleted AND the ones that will be refused with their own reason, because the
    merchant is confirming a run that will deliberately not touch part of what they
    selected, and "2 of these 5 cannot go" is the one thing they must see BEFORE
    typing the number.

    Deliberately unstyled, same as the other deletion modals: it inherits the
    panel's own typography.
--}}
@if ($overLimit)
    <div>
        <p><strong>{{ __('products.bulk.delete_over_limit', ['selected' => $selectedCount, 'limit' => $limit]) }}</strong></p>
        <p>{{ __('products.bulk.delete_over_limit_hint', ['limit' => $limit]) }}</p>
    </div>
@else
    <div>
        <p>{{ __('products.bulk.delete_impact_intro', [
            'selected' => $selectedCount,
            'deletable' => $deletableCount,
            'refused' => $refusedCount,
        ]) }}</p>

        @if ($refusedCount > 0)
            <p>{{ __('products.bulk.delete_impact_refused_note') }}</p>
        @endif
    </div>

    <div>
        <ul>
            @foreach ($rows as $row)
                <li>{{ __('products.bulk.delete_impact_row', [
                    'name' => $row['name'],
                    'verdict' => $row['deletable']
                        ? __('products.bulk.delete_impact_will_delete')
                        : $row['sentence'],
                ]) }}</li>
            @endforeach
        </ul>
    </div>

    <div>
        <p>{{ __('products.bulk.delete_impact_unrepeatable') }}</p>
        <p>{{ __('products.bulk.delete_impact_type_count', ['count' => $deletableCount]) }}</p>
    </div>
@endif
