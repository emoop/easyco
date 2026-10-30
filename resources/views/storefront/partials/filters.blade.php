{{-- CD-1b: the filters and the sort as ONE plain GET form, used by every placement (a bar above the list, a side panel, the phone drawer: storefront.css decides). Everything works without JavaScript through
     «Филтрирай»; the script only adds progress (the drawer's open / close, an auto-submit on a computer, closing the bar's dropdowns). The values are checkboxes (`filter[id][]`, answered with a 301 to the canonical
     `filter[id]=7,9`), the sort is a radio group whose default option has an EMPTY value (answered with a 301 to the URL without `sort`). A swatch colour is the fill of an inline SVG circle, a validated #rrggbb:
     there is no style attribute anywhere. The script gives the panel role="dialog" only while it is an open drawer (a static panel is no dialog). $filters = {groups, chips, clear, active}|null, $sorts = [{value, label, url, current}] --}}
@if (($filters ?? null) !== null || count($sorts ?? []) >= 2)
<div class="sf-fdrawer" id="sf-fdrawer" data-sf-fdrawer>
    <div class="sf-fdrawer-overlay" data-sf-fdrawer-close></div>
    <div class="sf-fdrawer-panel" aria-labelledby="sf-fdrawer-title" tabindex="-1">
        <div class="sf-fdrawer-head">
            <p class="sf-fdrawer-title" id="sf-fdrawer-title">{{ __('storefront.filter_and_sort') }}</p>
            <button class="sf-fdrawer-close" type="button" data-sf-fdrawer-close>
                <svg class="sf-close-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>
                <span class="sf-sr-only">{{ __('storefront.filter_close') }}</span>
            </button>
        </div>
        <form class="sf-filters-form" method="get" action="{{ $actionUrl }}">
            @if ($perPageHidden !== null)
                <input type="hidden" name="per_page" value="{{ $perPageHidden }}">
            @endif
            <div class="sf-fdrawer-body">
                @if (($filters['chips'] ?? []) !== [])
                    <div class="sf-filters-head">
                        <h2 class="sf-filters-title">{{ __('storefront.filters') }}</h2>
                        <ul class="sf-filter-chips">
                            @foreach ($filters['chips'] as $chip)
                                <li><a class="sf-filter-chip" href="{{ $chip['url'] }}" rel="nofollow">{{ $chip['label'] }} <span aria-hidden="true">×</span><span class="sf-sr">{{ __('storefront.filter_remove') }}</span></a></li>
                            @endforeach
                        </ul>
                        @if ($filters['clear'] !== null)
                            <a class="sf-filter-clear" href="{{ $filters['clear'] }}" rel="nofollow">{{ __('storefront.filter_clear_all') }}</a>
                        @endif
                    </div>
                @endif
                @if (count($sorts ?? []) >= 2)
                    <fieldset class="sf-fsort">
                        <legend class="sf-filter-name">{{ __('storefront.sort_title') }}</legend>
                        @foreach ($sorts as $sort)
                            <label class="sf-fv sf-fv-list">
                                <input class="sf-fv-input" type="radio" name="sort" value="{{ $sort['value'] }}" @checked($sort['current'])>
                                <span class="sf-fv-mark sf-fv-mark-round"></span>
                                <span class="sf-fv-text">{{ $sort['label'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif
                @if (($filters ?? null) !== null)
                    <div class="sf-fgroups">
                        @foreach ($filters['groups'] as $group)
                            <details class="sf-fgroup"{{ ($layout === 'sidebar' || $group['count'] > 0) ? ' open' : '' }}>
                                <summary class="sf-fgroup-summary"><span class="sf-filter-name">{{ $group['name'] }}</span>@if ($group['count'] > 0) <span class="sf-fgroup-count">({{ $group['count'] }})</span>@endif</summary>
                                <div class="sf-fgroup-panel">
                                    <ul class="sf-filter-values sf-fvalues-{{ $group['display'] }}">
                                        @foreach ($group['values'] as $value)
                                            <li>
                                                @if ($group['display'] === 'swatches' && $value['colour'] !== null)
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
                <button type="submit" class="sf-fbtn sf-fbtn-primary">{{ __('storefront.filter_apply') }}</button>
                <a class="sf-fbtn sf-fbtn-secondary" href="{{ $clearUrl }}" rel="nofollow">{{ __('storefront.filter_reset') }}</a>
            </div>
        </form>
    </div>
</div>
@endif
