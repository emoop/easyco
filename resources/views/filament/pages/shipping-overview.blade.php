{{--
    The read-only Shipping page (shipping-domain-design.md §12.3.5, stage 5a). Three blocks, one phone-friendly
    column: a status block, the overview of every zone with its methods as readable sentences, and the "Try it"
    tool. It DECIDES NOTHING — every sentence was built by a reader; a zone, method or class NAME is the
    merchant's own data and is escaped here like any other text. No severity, no colour, no action that writes.

    Plain CSS only (no theme build), the same posture as the "Needs attention" and Help pages. The "Try it" form
    is a plain Livewire form (wire:model + wire:submit to run()).
--}}
<x-filament-panels::page>
    <style>
        .shop-page { max-width: 44rem; }
        .shop-intro { opacity: .75; font-size: .9rem; }
        .shop-help { font-size: .85rem; margin: .2rem 0 1rem; }
        .shop-help a { text-decoration: underline; }
        .shop-section { margin-top: 1.75rem; }
        .shop-section > h2 { font-weight: 700; font-size: 1.05rem; margin-bottom: .5rem; }
        .shop-counts { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: .4rem; }
        .shop-count strong { font-size: 1.15rem; }
        .shop-note { opacity: .75; font-size: .9rem; }
        .shop-zone { border-top: 1px solid rgba(128,128,128,.25); padding-top: .75rem; margin-top: .75rem; }
        .shop-zone-name { font-weight: 600; margin: 0; }
        .shop-coverage { opacity: .75; font-size: .875rem; margin: .1rem 0; }
        .shop-methods { list-style: none; margin: .4rem 0 0; padding: 0; }
        .shop-methods li { margin: .2rem 0; font-size: .9rem; }
        .shop-method-name { font-weight: 500; }
        .shop-method-summary { opacity: .8; }
        .shop-field { margin-bottom: .6rem; }
        .shop-field > label { display: block; font-weight: 500; font-size: .875rem; margin-bottom: .2rem; }
        .shop-field input[type=text], .shop-field select, .shop-field input[type=number] { width: 100%; max-width: 22rem; }
        .shop-inline { display: flex; align-items: flex-end; gap: .75rem; flex-wrap: wrap; }
        .shop-line { border-top: 1px solid rgba(128,128,128,.2); padding-top: .5rem; margin-top: .5rem; }
        .shop-error { font-size: .8rem; text-decoration: underline; }
        .shop-result-facts { opacity: .8; font-size: .875rem; margin: .2rem 0; }
    </style>

    <div class="shop-page">
        <p class="shop-intro">{{ __('shipping.intro') }}</p>
        <p class="shop-help"><a href="{{ $overviewHelpUrl }}" target="_blank" rel="noopener noreferrer">{{ __('help.link') }}</a></p>

        {{-- Status --}}
        <section class="shop-section">
            <h2>{{ __('shipping.status.heading') }}</h2>
            <div class="shop-counts">
                <span class="shop-count"><strong>{{ $zoneCount }}</strong> {{ trans_choice('shipping.status.zones', $zoneCount) }}</span>
                <span class="shop-count"><strong>{{ $activeMethodCount }}</strong> {{ trans_choice('shipping.status.active_methods', $activeMethodCount) }}</span>
            </div>
            @if ($noZone)
                <p class="shop-note">{{ __('shipping.status.no_zone') }}</p>
            @endif
        </section>

        {{-- Overview --}}
        <section class="shop-section">
            <h2>{{ __('shipping.overview.heading') }}</h2>
            <p class="shop-coverage">{{ __('shipping.overview.first_match_wins') }}</p>
            <p class="shop-help"><a href="{{ $zonesUrl }}">{{ __('shipping.overview.edit_zones') }}</a> · <a href="{{ $methodsUrl }}">{{ __('shipping.overview.edit_methods') }}</a> · <a href="{{ $classesUrl }}">{{ __('shipping.overview.edit_classes') }}</a></p>

            @if ($zones === [])
                <p class="shop-note">{{ __('shipping.overview.no_zones') }}</p>
            @else
                @foreach ($zones as $zone)
                    <div class="shop-zone">
                        <p class="shop-zone-name">{{ __('shipping.overview.zone_number', ['number' => $zone['number']]) }} · {{ $zone['name'] }}</p>
                        <p class="shop-coverage">{{ $zone['coverage'] }}</p>

                        @if ($zone['methods'] === [])
                            <p class="shop-note">{{ __('shipping.overview.no_methods') }}</p>
                        @else
                            <ul class="shop-methods">
                                @foreach ($zone['methods'] as $method)
                                    <li><span class="shop-method-name">{{ $method['name'] }}</span> — <span class="shop-method-summary">{{ $method['summary'] }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>

        {{-- Try it --}}
        <section class="shop-section">
            <h2>{{ __('shipping.try_it.heading') }}</h2>
            <p class="shop-coverage">{{ __('shipping.try_it.intro') }}</p>

            <form wire:submit="run">
                <div class="shop-field">
                    <label for="shop-country">{{ __('shipping.try_it.country') }}</label>
                    <select id="shop-country" wire:model="country">
                        <option value="">{{ __('shipping.try_it.none') }}</option>
                        @foreach ($countryOptions as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('country') <span class="shop-error">{{ $message }}</span> @enderror
                </div>

                <div class="shop-field">
                    <label for="shop-settlement">{{ __('shipping.try_it.settlement') }}</label>
                    <input id="shop-settlement" type="text" maxlength="255" wire:model="settlement">
                    @error('settlement') <span class="shop-error">{{ $message }}</span> @enderror
                </div>

                <div class="shop-field">
                    <label for="shop-postcode">{{ __('shipping.try_it.postcode') }}</label>
                    <input id="shop-postcode" type="text" maxlength="20" wire:model="postcode">
                    @error('postcode') <span class="shop-error">{{ $message }}</span> @enderror
                </div>

                <div class="shop-field">
                    <label><input type="checkbox" wire:model="pickupPoint"> {{ __('shipping.try_it.pickup') }}</label>
                </div>

                <div class="shop-field">
                    <label for="shop-goods">{{ __('shipping.try_it.goods') }}</label>
                    <input id="shop-goods" type="text" maxlength="20" wire:model="goods">
                    @error('goods') <span class="shop-error">{{ $message }}</span> @enderror
                </div>

                <div class="shop-field">
                    <label>{{ __('shipping.try_it.lines') }}</label>

                    @foreach ($lines as $index => $line)
                        <div class="shop-line shop-inline" wire:key="line-{{ $index }}">
                            <div>
                                <label for="shop-class-{{ $index }}">{{ __('shipping.try_it.class') }}</label>
                                <select id="shop-class-{{ $index }}" wire:model="lines.{{ $index }}.class">
                                    <option value="">{{ __('shipping.try_it.no_class') }}</option>
                                    @foreach ($classOptions as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="shop-qty-{{ $index }}">{{ __('shipping.try_it.quantity') }}</label>
                                <input id="shop-qty-{{ $index }}" type="number" min="1" max="9999" wire:model="lines.{{ $index }}.quantity">
                            </div>
                            <button type="button" wire:click="removeLine({{ $index }})">{{ __('shipping.try_it.remove_line') }}</button>
                            @error("lines.{$index}.quantity") <span class="shop-error">{{ $message }}</span> @enderror
                        </div>
                    @endforeach

                    <button type="button" wire:click="addLine">{{ __('shipping.try_it.add_line') }}</button>
                </div>

                <button type="submit">{{ __('shipping.try_it.submit') }}</button>
            </form>


            @if ($result !== null)
                <div class="shop-section">
                    <h2>{{ __('shipping.try_it.result_heading') }}</h2>

                    @if ($result['matched'])
                        <p>{{ __('shipping.try_it.matched_zone', ['name' => $result['zoneName']]) }}</p>
                    @else
                        <p class="shop-note">{{ __('shipping.try_it.refused') }}</p>
                    @endif

                    <p class="shop-result-facts">
                        {{ __('shipping.try_it.destination_heading') }} —
                        {{ __('shipping.try_it.destination_settlement', ['value' => $result['settlement'] !== '' ? $result['settlement'] : __('shipping.try_it.none')]) }},
                        {{ __('shipping.try_it.destination_postcode', ['value' => $result['postcode'] !== '' ? $result['postcode'] : __('shipping.try_it.none')]) }}
                    </p>
                    <p class="shop-result-facts">{{ __('shipping.try_it.goods', ['amount' => $result['goods']]) }}</p>

                    @if ($result['matched'])
                        @if ($result['methods'] === [])
                            <p class="shop-note">{{ __('shipping.try_it.no_methods') }}</p>
                        @else
                            @foreach ($result['groups'] as $group)
                            @if ($result['showGroupNames'])
                                <p class="shop-zone-name">{{ $group['courier'] ?? __('shipping.try_it.ungrouped') }}</p>
                            @endif
                            <ul class="shop-methods">
                                @foreach ($group['methods'] as $method)
                                    <li>
                                        <span class="shop-method-name">{{ $method['name'] }}</span>
                                        <span class="shop-method-summary">— {{ $method['summary'] }}</span>
                                        <div class="shop-result-facts">
                                            @if ($method['classMode'])
                                                {{ $method['classMode'] }} ·
                                            @endif
                                            @if ($method['needsQuote'])
                                                {{ __('shipping.try_it.needs_quote') }}
                                            @else
                                                {{ __('shipping.try_it.price', ['amount' => $method['price']]) }}
                                            @endif
                                            @if ($method['needsMore'])
                                                · {{ __('shipping.try_it.remaining', ['amount' => $method['remaining']]) }}
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            @endforeach
                        @endif

                        @if ($result['hint'] !== null)
                            <p class="shop-result-facts">{{ __('shipping.try_it.hint', ['text' => $result['hint']]) }}</p>
                        @endif
                    @endif
                </div>
            @endif

            <p class="shop-help"><a href="{{ $tryItHelpUrl }}" target="_blank" rel="noopener noreferrer">{{ __('help.link') }}</a></p>
        </section>
    </div>
</x-filament-panels::page>

