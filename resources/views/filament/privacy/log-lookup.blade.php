{{-- ACC-4e-2: «Проверка по имейл». The typed email stays in this field only (no URL, no storage, no log); the answer holds states and dates. --}}
<div class="pl-lookup">
    <style>
        .pl-lookup { margin-bottom: 1rem; }
        .pl-lookup form { display: flex; flex-wrap: wrap; gap: .5rem; align-items: end; }
        .pl-lookup label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .2rem; }
        .pl-lookup input { min-width: 16rem; max-width: 100%; }
        .pl-answer { margin-top: .6rem; font-size: .875rem; }
        .pl-answer p + p { margin-top: .2rem; }
        .pl-muted { opacity: .75; }
    </style>
    <x-filament::section compact :heading="__('privacy_admin.log.lookup.heading')">
        <form wire:submit="lookup" novalidate>
            <div>
                <label for="pl-email">{{ __('privacy_admin.log.lookup.label') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="pl-email" type="text" inputmode="email" autocomplete="off" wire:model="lookupEmail" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button type="submit" size="sm">{{ __('privacy_admin.log.lookup.button') }}</x-filament::button>
        </form>

        @if ($this->lookupResult !== null)
            <div class="pl-answer" role="status">
                @switch ($this->lookupResult['state'])
                    @case('invalid') <p>{{ __('privacy_admin.log.lookup.invalid') }}</p> @break
                    @case('unavailable') <p>{{ __('privacy_admin.log.unavailable') }}</p> @break
                    @case('none') <p>{{ __('privacy_admin.log.lookup.none') }}</p> @break
                    @case('found')
                        <p><strong>{{ __('privacy_admin.log.lookup.found', ['date' => $this->lookupResult['date']]) }}</strong></p>
                        @if ($this->lookupResult['suppressed']) <p>{{ __('privacy_admin.log.lookup.suppressed') }}</p> @endif
                        @if ($this->lookupResult['lifted']) <p class="pl-muted">{{ __('privacy_admin.log.lookup.lifted', ['date' => $this->lookupResult['lifted']]) }}</p> @endif
                    @break
                @endswitch
            </div>
        @endif
    </x-filament::section>
</div>
