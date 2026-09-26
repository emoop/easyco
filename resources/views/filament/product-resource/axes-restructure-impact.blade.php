{{--
    The body of the "Change axes" confirmation — catalog-domain-design.md
    §3.19.8 C.

    Rendered as the action's Placeholder content, so it sits BETWEEN the axes
    inputs and the confirmation fields inside ONE modal schema, in §3.19.8 C's
    own order: the new axes, then this impact, then the confirmation.

    Nothing here is computed beyond formatting — every list, count and summary
    comes from App\Services\AxesRestructureImpact (the same service the
    operation re-derives its plan from), and the refusal sentence arrives
    already translated from App\Services\AxesRestructureRefusalMessage. The
    per-variation attribute labels are pre-formatted by the page for the same
    reason: no merchant-facing sentence is built in this file.

    Deliberately unstyled: it inherits the panel's own typography, like the two
    delete modals' bodies.
--}}
@if (! $impact->canApply())
    <div>
        <p><strong>{{ $refusalMessage }}</strong></p>
    </div>
@else
    <div>
        <p>{{ __('products.axes_restructure.impact_intro', ['current' => $impact->currentAxesSummary]) }}</p>
        <p>{{ __('products.axes_restructure.impact_new', ['new' => $impact->newAxesSummary]) }}</p>

        @if (! $impact->axesDiffer)
            {{-- R1: the identical set. Still confirmable — it is simply a no-op. --}}
            <p><strong>{{ __('products.axes_restructure.impact_unchanged_axes') }}</strong></p>
        @endif

        @if (! $impact->affectsVariations())
            <p>{{ __('products.axes_restructure.impact_no_variations') }}</p>
        @endif
    </div>

    @if ($impact->willBeDeleted !== [])
        <div>
            <p>{{ __('products.axes_restructure.impact_will_delete', ['count' => count($impact->willBeDeleted)]) }}</p>

            <ul>
                @foreach ($impact->willBeDeleted as $variation)
                    <li>{{ $variation->sku }} — {{ $variationLabels[$variation->variationId] ?? __('products.axes_restructure.impact_no_attributes') }}</li>
                @endforeach
            </ul>

            <p>{{ __('products.axes_restructure.impact_delete_note') }}</p>
        </div>
    @endif

    @if ($impact->willBeArchived !== [])
        <div>
            <p>{{ __('products.axes_restructure.impact_will_archive', ['count' => count($impact->willBeArchived)]) }}</p>

            <ul>
                @foreach ($impact->willBeArchived as $variation)
                    <li>{{ $variation->sku }} — {{ $variationLabels[$variation->variationId] ?? __('products.axes_restructure.impact_no_attributes') }}</li>
                @endforeach
            </ul>

            {{-- §3.19.9's own consequence, said where it applies: without
                 PRODUCT_DELETE nothing is deleted at all. --}}
            @if (! $impact->mayDelete)
                <p><strong>{{ __('products.axes_restructure.impact_no_delete_permission') }}</strong></p>
            @endif

            <p>{{ __('products.axes_restructure.impact_archive_note') }}</p>
        </div>
    @endif

    @if ($impact->willBecomeUnrestorable !== [])
        <div>
            <p>{{ __('products.axes_restructure.impact_will_become_unrestorable', ['count' => count($impact->willBecomeUnrestorable)]) }}</p>

            <ul>
                @foreach ($impact->willBecomeUnrestorable as $variation)
                    <li>{{ $variation->sku }} — {{ $variationLabels[$variation->variationId] ?? __('products.axes_restructure.impact_no_attributes') }}</li>
                @endforeach
            </ul>

            {{-- §3.17's documented trade-off, stated BEFORE the merchant
                 commits to it rather than discovered at the Restore action. --}}
            <p>{{ __('products.axes_restructure.impact_restorable_note') }}</p>
        </div>
    @endif

    @if ($impact->axesDiffer)
        <div>
            <p>{{ __('products.axes_restructure.impact_generated') }}</p>
            <p>{{ __('products.axes_restructure.impact_unsaved_note') }}</p>
        </div>
    @endif
@endif
