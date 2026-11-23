{{-- ACC-4e-1: the three tabs of the «Клиенти» list (Всички / С профил / Само гости). The active one is the Livewire property `activeTab` (kept in the URL). --}}
<x-filament::tabs :label="__('customers.title')" class="cus-tabs">
    @foreach (['all' => 'tabs.all', 'accounts' => 'tabs.accounts', 'guests' => 'tabs.guests'] as $value => $label)
        <x-filament::tabs.item :active="$this->activeTab === $value" wire:click="$set('activeTab', '{{ $value }}')">
            {{ __('customers.'.$label) }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
