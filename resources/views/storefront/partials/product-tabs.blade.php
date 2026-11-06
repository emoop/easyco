{{-- P4b: a «Табове» slot of the product page: the sections it names, resolved for THIS product, as CSS-only tabs (radio inputs + labels: no script, no inline style). The inputs come first so a checked one selects its panel by
     sibling selectors (storefront.css, up to 8 tabs); the first printed tab is checked; arrow keys move between the radios of the group. The ids carry the slot's number, so two tab slots never clash. $slot = {tabs: [{key, title, html}], heading},
     $n = the slot's number on the page. --}}
<div class="sf-hp sf-hp-gap-0 sf-lp-slot sf-lp-slot-bottom">
    @if (($slot['heading'] ?? null) !== null)
        <section class="sf-hp-section sf-hp-heading">
            @include('storefront.partials.home.heading', ['item' => ['settings' => ['text' => $slot['heading'], 'level' => 'h2', 'lines' => 'lines', 'thickness' => '1']]])
        </section>
    @endif
    <section class="sf-hp-section sf-ptabs">
        @foreach ($slot['tabs'] as $tab)
            <input class="sf-ptab-input" type="radio" name="sf-ptabs-{{ $n }}" id="sf-ptab-{{ $n }}-{{ $loop->iteration }}"{{ $loop->first ? ' checked' : '' }}>
        @endforeach
        <div class="sf-ptab-list">
            @foreach ($slot['tabs'] as $tab)
                <label class="sf-ptab-label" for="sf-ptab-{{ $n }}-{{ $loop->iteration }}">{{ $tab['title'] }}</label>
            @endforeach
        </div>
        <div class="sf-ptab-panels">
            @foreach ($slot['tabs'] as $tab)
                <div class="sf-ptab-panel">{!! $tab['html'] !!}</div>
            @endforeach
        </div>
    </section>
</div>
