{{-- ACC-4e-2: the body of the «Забрави» dialog (acc-4-gdpr-design.md section 4, 10): what goes, what stays, what to know. Every value is escaped by Blade; no email, no phone. --}}
@php
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
    $date = fn ($value): string => \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('d.m.Y');
@endphp
<div class="fg-summary" style="font-size: .875rem; display: grid; gap: .6rem">
    <p style="display: flex; flex-wrap: wrap; gap: .4rem; align-items: center">
        <x-filament::badge color="danger" size="sm">{{ __('privacy_admin.forget.admin_only') }}</x-filament::badge>
        <strong>{{ __('privacy_admin.forget.lead') }}</strong>
    </p>

    <div>
        <p style="font-weight: 600">{{ __('privacy_admin.forget.delete_title') }}</p>
        <p>{{ __('privacy_admin.forget.delete_text') }}</p>
    </div>

    <div>
        <p style="font-weight: 600">{{ __('privacy_admin.forget.stay_title') }}</p>
        <ul style="list-style: disc; padding-inline-start: 1.1rem">
            <li>{{ __('privacy_admin.forget.stay_orders', ['years' => trans_choice('privacy_admin.forget.years', $s['years'], ['count' => $s['years']])]) }}</li>
            @if ($s['block'] !== null)
                <li>{{ $s['block']['forever'] ? __('privacy_admin.forget.stay_block_forever') : __('privacy_admin.forget.stay_block', ['date' => $date($s['block']['until'])]) }}</li>
            @endif
        </ul>
    </div>

    @if ($s['open_orders'] !== [])
        @php
            $links = collect($s['open_orders'])->map(fn ($id) => '<a href="'.e(\App\Filament\Resources\OrderResource::getUrl('view', ['record' => $id])).'" target="_blank" style="text-decoration: underline; font-weight: 600">#'.(int) $id.'</a>')->implode(', ');
            $more = $s['open_more'] > 0 ? ' '.e(__('privacy_admin.forget.open_orders_more', ['count' => $s['open_more']])) : '';
        @endphp
        <p role="alert" style="border: 1px solid rgb(245 158 11 / .7); border-radius: .5rem; padding: .4rem .6rem">
            {!! str_replace('@@ORDERS@@', $links.$more, e(__('privacy_admin.forget.open_orders', ['orders' => '@@ORDERS@@']))) !!}
        </p>
    @endif

    @if ($s['forgotten_at'] !== null)
        <p style="opacity: .85">{{ __('privacy_admin.forget.already', ['date' => $date($s['forgotten_at'])]) }}</p>
    @endif

    @if ($s['linked_at'] !== null)
        <p style="opacity: .85">{{ __('privacy_admin.forget.linked', ['date' => $date($s['linked_at'])]) }}</p>
    @endif
</div>
