{{-- CT-3: the category's filters (the CD-1a slot). Plain GET links: nothing needs JavaScript, nothing is a form, nothing carries a style attribute (a swatch colour is the fill of an inline SVG circle, a validated #rrggbb). Every link is rel="nofollow":
     a filtered page is not for crawlers. $filters = {groups: [{id, name, display: list|links|swatches, values: [{id, label, colour, selected, url}]}], chips: [{label, url}], clear: ?url} --}}
<section class="sf-filters" aria-labelledby="sf-filters-title">
    <div class="sf-filters-head">
        <h2 class="sf-filters-title" id="sf-filters-title">{{ __('storefront.filters') }}</h2>
        @if ($filters['chips'] !== [])
            <ul class="sf-filter-chips">
                @foreach ($filters['chips'] as $chip)
                    <li><a class="sf-filter-chip" href="{{ $chip['url'] }}" rel="nofollow">{{ $chip['label'] }} <span aria-hidden="true">×</span><span class="sf-sr">{{ __('storefront.filter_remove') }}</span></a></li>
                @endforeach
            </ul>
        @endif
        @if ($filters['clear'] !== null)
            <a class="sf-filter-clear" href="{{ $filters['clear'] }}" rel="nofollow">{{ __('storefront.filter_clear_all') }}</a>
        @endif
    </div>
    <div class="sf-filters-grid">
        @foreach ($filters['groups'] as $group)
            <div class="sf-filter sf-filter-{{ $group['display'] }}">
                <h3 class="sf-filter-name" id="sf-filter-{{ $group['id'] }}">{{ $group['name'] }}</h3>
                <ul class="sf-filter-values" aria-labelledby="sf-filter-{{ $group['id'] }}">
                    @foreach ($group['values'] as $value)
                        <li>
                            @if ($group['display'] === 'swatches' && $value['colour'] !== null)
                                <a @class(['sf-fv', 'sf-fv-dot', 'sf-fv-on' => $value['selected']]) href="{{ $value['url'] }}" rel="nofollow" title="{{ $value['label'] }}"><svg class="sf-fv-circle" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="{{ $value['colour'] }}"/></svg><span class="sf-sr">{{ $value['label'] }}@if ($value['selected']), {{ __('storefront.filter_selected') }}@endif</span></a>
                            @else
                                <a @class(['sf-fv', 'sf-fv-list' => $group['display'] === 'list', 'sf-fv-link' => $group['display'] !== 'list', 'sf-fv-on' => $value['selected']]) href="{{ $value['url'] }}" rel="nofollow">{{ $value['label'] }}@if ($value['selected'])<span class="sf-sr">, {{ __('storefront.filter_selected') }}</span>@endif</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>
