{{-- CD-1b / CD-1c: the filters and the sort as ONE plain GET form: the PHONE DRAWER (and the side panel of that layout on a computer). Everything works without JavaScript through «Филтрирай»; the script only adds
     progress (the drawer's open / close, one screen at a time, an auto-submit in the side panel). The drawer is two levels with no script of its own: level 1 is a list of rows (the sort first, then one per filter; a
     row is the <summary> of a native <details>), level 2 is the OPEN <details> laid over the drawer (its summary becomes the header with a back arrow). The values are checkboxes (`filter[id][]`, answered with a
     301 to the canonical `filter[id]=7,9`); the sort is a radio group whose default option has an EMPTY value (a 301 to the URL without `sort`). A swatch colour is the fill of an inline SVG circle, a validated
     #rrggbb: there is no style attribute anywhere. The script gives the panel role="dialog" only while it is an open drawer. $filters = {groups, clear, active}|null, $sorts = [{value, label, url, current}] --}}
@if (($filters ?? null) !== null || count($sorts ?? []) >= 2)
<div class="sf-fdrawer" id="sf-fdrawer" data-sf-fdrawer>
    <div class="sf-fdrawer-overlay" data-sf-fdrawer-close></div>
    <div class="sf-fdrawer-panel" aria-labelledby="sf-fdrawer-title" tabindex="-1">
        <div class="sf-fdrawer-head">
            <button class="sf-fdrawer-close" type="button" data-sf-fdrawer-close>
                <svg class="sf-close-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>
                <span class="sf-sr-only">{{ __('storefront.filter_close') }}</span>
            </button>
            <p class="sf-fdrawer-title" id="sf-fdrawer-title">{{ __('storefront.filters') }}</p>
        </div>
        <form class="sf-filters-form" method="get" action="{{ $actionUrl }}">
            @if ($perPageHidden !== null)
                <input type="hidden" name="per_page" value="{{ $perPageHidden }}">
            @endif
            @if (($searchQuery ?? null) !== null)
                {{-- SRCH-1: the results page keeps its query when the sort is chosen --}}
                <input type="hidden" name="q" value="{{ $searchQuery }}">
            @endif
            <div class="sf-fdrawer-body">
                <p class="sf-fcount">{{ __('storefront.products_total', ['count' => $total]) }}</p>
                @if (count($sorts ?? []) >= 2)
                    <details class="sf-fgroup sf-fgroup-sort">
                        <summary class="sf-fgroup-summary"><span class="sf-fgroup-name">{{ __('storefront.sort_title') }}</span><span class="sf-fgroup-current">{{ collect($sorts)->firstWhere('current', true)['label'] ?? $sorts[0]['label'] }}</span></summary>
                        <div class="sf-fgroup-panel">
                            @foreach ($sorts as $sort)
                                <label class="sf-fv sf-fv-list">
                                    <input class="sf-fv-input" type="radio" name="sort" value="{{ $sort['value'] }}" @checked($sort['current'])>
                                    <span class="sf-fv-mark sf-fv-mark-round"></span>
                                    <span class="sf-fv-text">{{ $sort['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </details>
                @endif
                @if (($filters ?? null) !== null)
                    <div class="sf-fgroups">
                        @foreach ($filters['groups'] as $group)
                            <details class="sf-fgroup"{{ $layout === 'sidebar' ? ' open' : '' }}>
                                <summary class="sf-fgroup-summary"><span class="sf-fgroup-name">{{ $group['name'] }}</span><span class="sf-fgroup-current">{{ $group['current'] }}</span></summary>
                                <div class="sf-fgroup-panel">
                                    <p class="sf-fgroup-meta">{{ __('storefront.filters_selected', ['count' => $group['count']]) }}</p>
                                    <ul class="sf-filter-values sf-fvalues-{{ $group['display'] === 'switch' ? 'list' : $group['display'] }}">
                                        @foreach ($group['values'] as $value)
                                            <li>
                                                @if ($group['display'] === 'switch')
                                                    {{-- P2: «Препоръчани» — one checkbox, `featured=1` (the look of a list value) --}}
                                                    <label class="sf-fv sf-fv-list">
                                                        <input class="sf-fv-input" type="checkbox" name="featured" value="1" @checked($value['selected'])>
                                                        <span class="sf-fv-mark"></span>
                                                        <span class="sf-fv-text">{{ $value['label'] }}</span>
                                                    </label>
                                                @elseif ($group['display'] === 'swatches' && $value['colour'] !== null)
                                                    <label class="sf-fv sf-fv-dot" title="{{ $value['label'] }}">
                                                        <input class="sf-fv-input" type="checkbox" name="filter[{{ $group['id'] }}][]" value="{{ $value['id'] }}" @checked($value['selected'])>
                                                        <svg class="sf-fv-circle" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="{{ $value['colour'] }}"/></svg>
                                                        <span class="sf-sr">{{ $value['label'] }}</span>
                                                    </label>
                                                @elseif ($group['display'] === 'list')
                                                    <label class="sf-fv sf-fv-list">
                                                        <input class="sf-fv-input" type="checkbox" name="filter[{{ $group['id'] }}][]" value="{{ $value['id'] }}" @checked($value['selected'])>
                                                        <span class="sf-fv-mark"></span>
                                                        <span class="sf-fv-text">{{ $value['label'] }}</span>
                                                    </label>
                                                @elseif ($group['display'] === 'buttons')
                                                    <label class="sf-fv sf-fv-chip">
                                                        <input class="sf-fv-input" type="checkbox" name="filter[{{ $group['id'] }}][]" value="{{ $value['id'] }}" @checked($value['selected'])>
                                                        <span class="sf-fv-text">{{ $value['label'] }}</span>
                                                    </label>
                                                @else
                                                    <label class="sf-fv sf-fv-link">
                                                        <input class="sf-fv-input" type="checkbox" name="filter[{{ $group['id'] }}][]" value="{{ $value['id'] }}" @checked($value['selected'])>
                                                        <span class="sf-fv-text">{{ $value['label'] }}</span>
                                                    </label>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="sf-fdrawer-foot">
                @if (($filters['clear'] ?? null) !== null)
                    <a class="sf-fclear" href="{{ $filters['clear'] }}" rel="nofollow">{{ __('storefront.filter_reset') }}</a>
                @endif
                <button type="submit" class="sf-fbtn sf-fbtn-outline">{{ __('storefront.filter_apply') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
