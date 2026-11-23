@php($view = \App\Privacy\ForgetLogPresenter::clearView($getRecord()))
<div style="font-size: .8125rem; white-space: nowrap">
    @if ($view['visible'])
        <x-filament::badge size="sm" :color="$view['amber'] ? 'warning' : 'gray'">{{ trans_choice('privacy_admin.log.clear_days', $view['days'], ['count' => $view['days']]) }}</x-filament::badge>
    @else
        <span style="opacity: .7">{{ __('privacy_admin.log.clear_expired') }}</span>
    @endif
</div>
