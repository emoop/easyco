{{-- CD-1c: the BAR of «filters on top» (a computer, from 56.25rem): a grid of dropdown controls — the sort first, then one per filter. Each is a native <details> (the `name` makes it one open at a time) whose panel is
     ONE column of plain links: a value is a link that toggles it (applies at once, rel="nofollow", the canonical URL); the control of a filter with a selection shows «Име: избраните». No JavaScript is needed;
     the same page's FORM is the phone drawer. A swatch colour is the fill of an inline SVG circle (a validated #rrggbb); there is no style attribute. $filters = {groups: [{id, name, display, count, current, clear,
     values: [{label, colour, selected, url}]}], clear, active}|null, $sorts = [{label, url, current}] --}}
@if (($filters ?? null) !== null || count($sorts ?? []) >= 2)
<div class="sf-cbar">
    @if (count($sorts ?? []) >= 2)
        <details class="sf-bctl sf-bctl-sort" name="sf-cbar">
            <summary class="sf-bctl-sum"><span class="sf-bctl-text sf-bctl-strong">{{ collect($sorts)->firstWhere('current', true)['label'] ?? $sorts[0]['label'] }}</span></summary>
            <div class="sf-bctl-panel">
                <ul class="sf-bctl-values sf-bctl-values-links">
                    @foreach ($sorts as $sort)
                        <li><a class="sf-bv sf-bv-link{{ $sort['current'] ? ' sf-bv-on' : '' }}" href="{{ $sort['url'] }}" rel="nofollow"@if ($sort['current']) aria-current="true"@endif>{{ $sort['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        </details>
    @endif
    @foreach (($filters['groups'] ?? []) as $group)
        <details class="sf-bctl" name="sf-cbar">
            <summary class="sf-bctl-sum"><span class="sf-bctl-text">@if ($group['count'] > 0)<strong class="sf-bctl-strong">{{ $group['name'] }}</strong>: {{ $group['current'] }}@else{{ $group['name'] }}@endif</span></summary>
            <div class="sf-bctl-panel">
                <div class="sf-bctl-meta">
                    <span class="sf-bctl-chosen">{{ __('storefront.filters_selected', ['count' => $group['count']]) }}</span>
                    @if ($group['clear'] !== null)
                        <a class="sf-bctl-clear" href="{{ $group['clear'] }}" rel="nofollow">{{ __('storefront.filter_reset') }}</a>
                    @endif
                </div>
                <ul class="sf-bctl-values sf-bctl-values-{{ $group['display'] }}">
                    @foreach ($group['values'] as $value)
                        <li>
                            @if ($group['display'] === 'swatches' && $value['colour'] !== null)
                                <a class="sf-bv sf-bv-dot{{ $value['selected'] ? ' sf-bv-on' : '' }}" href="{{ $value['url'] }}" rel="nofollow" title="{{ $value['label'] }}"><svg class="sf-fv-circle" width="28" height="28" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="{{ $value['colour'] }}"/></svg><span class="sf-sr">{{ $value['label'] }}@if ($value['selected']), {{ __('storefront.filter_selected') }}@endif</span></a>
                            @else
                                <a class="sf-bv {{ $group['display'] === 'list' ? 'sf-bv-list' : 'sf-bv-link' }}{{ $value['selected'] ? ' sf-bv-on' : '' }}" href="{{ $value['url'] }}" rel="nofollow">{{ $value['label'] }}@if ($value['selected'])<span class="sf-sr">, {{ __('storefront.filter_selected') }}</span>@endif</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </details>
    @endforeach
</div>
@endif
