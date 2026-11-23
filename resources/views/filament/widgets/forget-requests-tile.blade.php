{{-- ACC-4e-2: the dashboard tile «Заявка за забравяне». Only drawn when PRIVACY_MANAGE and something waits for manual steps (ForgetRequestsTile::canView). --}}
<x-filament-widgets::widget>
    <x-filament::section compact :heading="__('privacy_admin.tile.heading')">
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; font-size: .875rem">
            <div>
                <p style="font-size: 1.5rem; font-weight: 700; line-height: 1.2">{{ $summary['count'] }}</p>
                <p>{{ trans_choice('privacy_admin.tile.count', $summary['count'], ['count' => $summary['count']]) }}</p>
                @if ($summary['email'] !== null)
                    <p style="opacity: .75; margin-top: .25rem">{{ __('privacy_admin.tile.soonest', ['email' => $summary['email'], 'days' => trans_choice('privacy_admin.log.clear_days', $summary['days'], ['count' => $summary['days']])]) }}</p>
                @endif
            </div>
            <x-filament::button tag="a" :href="$logUrl" size="sm">{{ __('privacy_admin.tile.open') }}</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
