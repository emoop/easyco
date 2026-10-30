{{-- CD-1b: the bar above the list. Below 64rem: the trigger of the filters drawer on the left, the count on the right. From 64rem: the count on the left and the sort (a <details> of plain links) on the right.
     The trigger is printed only when there is something to open (a filter or a sort with two options); without the script the drawer is a plain block and the trigger is hidden (storefront.css). --}}
<div class="sf-toolbar">
    @if (($filters ?? null) !== null || count($sorts ?? []) >= 2)
        <button type="button" class="sf-ftrigger" data-sf-fdrawer-open aria-controls="sf-fdrawer" aria-expanded="false">
            <svg class="sf-ftrigger-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="square" aria-hidden="true" focusable="false"><path d="M9 6h6M6 12h12M3 18h18"/></svg>
            <span class="sf-ftrigger-text">{{ __('storefront.filter_and_sort') }}@if (($filters['active'] ?? 0) > 0) <span class="sf-ftrigger-count">({{ $filters['active'] }})</span>@endif</span>
            <svg class="sf-ftrigger-arrow" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
        </button>
    @endif
    <p class="sf-count">{{ trans_choice('storefront.products_count', $total, ['count' => $total]) }}</p>
    @if (count($sorts ?? []) >= 2)
        <details class="sf-sortdd">
            <summary class="sf-sortdd-summary"><span class="sf-sortdd-label">{{ __('storefront.sort_label') }}</span> {{ collect($sorts)->firstWhere('current', true)['label'] ?? $sorts[0]['label'] }}</summary>
            <ul class="sf-sortdd-list">
                @foreach ($sorts as $sort)
                    <li><a class="sf-sortdd-link" href="{{ $sort['url'] }}" rel="nofollow"@if ($sort['current']) aria-current="true"@endif>{{ $sort['label'] }}</a></li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
