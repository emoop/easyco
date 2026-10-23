{{-- «Дизайн» (DS-2a): the zone list, the palette, the schema of the layout, the preview frame and the properties. Everything is a real <button type="button">; the state lives in the Livewire page
     (app/Filament/Pages/Design/DesignScreen.php). The admin sidebar starts collapsed on this page and opens again with the header button «Към админа» (and when the page is left). --}}
<x-filament-panels::page>
    @vite('resources/css/design-screen.css')

    <div class="ds" x-data="{ was: null }" x-init="was = $store.sidebar.isOpen; $store.sidebar.close()" x-on:beforeunload.window="was && $store.sidebar.open()">
        <nav class="ds-zones" aria-label="{{ __('design.zones_title') }}">
            <h2 class="ds-h">{{ __('design.zones_title') }}</h2>
            <ul>
                @foreach (['topbanner', 'header', 'footer', 'checkout', 'content', 'sidebar'] as $zone)
                    <li>
                        @if ($zone === 'header')
                            <button type="button" class="ds-zone ds-zone-on" aria-current="true">{{ __('design.zones.'.$zone) }}</button>
                        @else
                            <button type="button" class="ds-zone" disabled title="{{ __('design.soon') }}">{{ __('design.zones.'.$zone) }} <small>{{ __('design.soon') }}</small></button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="ds-main">
            <div class="ds-bar">
                <div class="ds-devices" role="group" aria-label="{{ __('design.device_label') }}">
                    @foreach (['desktop', 'mobile'] as $device)
                        <button type="button" class="ds-btn @if ($this->device === $device) ds-btn-on @endif" aria-pressed="{{ $this->device === $device ? 'true' : 'false' }}" wire:click="setDevice('{{ $device }}')">{{ __('design.devices.'.$device) }}</button>
                    @endforeach
                </div>
                @if ($this->dirty)
                    <span class="ds-tag ds-tag-warn">{{ __('design.preview.unsaved') }}</span>
                @endif
            </div>

            @include('filament.pages.design.partials.palette')
            @include('filament.pages.design.partials.schema')
            @include('filament.pages.design.partials.preview')
        </div>

        <aside class="ds-props" aria-labelledby="ds-props-title">
            <h2 class="ds-h" id="ds-props-title">{{ __('design.props.title') }}</h2>
            <form novalidate wire:submit="apply">
                {{ $this->getSchema('propsForm') }}
                <button type="submit" class="ds-btn ds-btn-primary">{{ __('design.actions.apply') }}</button>
            </form>
        </aside>
    </div>
</x-filament-panels::page>
