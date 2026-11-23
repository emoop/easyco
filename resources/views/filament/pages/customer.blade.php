{{--
    ACC-4e-1: the admin customer page. Two columns on a wide screen (the data on the left, the note and the cash-on-delivery card on the right), ONE column on a phone.
    Plain markup + a few rules of its own, the same posture as the «Нуждае се от внимание» page. Every value is escaped by Blade; NO clear email or phone of a privacy request is ever printed.
    An explanation is an info icon (admin-ui-standard.md rule 1), never a paragraph under a field.
--}}
@php
    use App\Filament\Design\InfoHint;
    use Filament\Support\Facades\FilamentTimezone;

    $tz = FilamentTimezone::get();
    $when = fn ($value, string $format = 'd.m.Y H:i'): string => $value === null ? '' : \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format($format);
    $kindLabel = fn (string $kind): string => __('customers.requests.kind.'.$kind);
    $confirmed = $d['requests']->first(fn ($row): bool => $row->status === 'confirmed');
    $awaiting = $d['requests']->first(fn ($row): bool => $row->status === 'awaiting_confirmation' && $row->created_at->copy()->addDays(\App\Privacy\PrivacyConfirmationRequester::VALID_DAYS)->isFuture());
@endphp
<x-filament-panels::page>
    <style>
        .cu-badges { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin-bottom: .75rem; }
        .cu-strip { border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; padding: .5rem .75rem; margin-bottom: .75rem; font-size: .875rem; }
        .cu-strip + .cu-strip { margin-top: -.25rem; }
        .cu-strip-confirmed { border-color: rgb(34 197 94 / .6); }
        .cu-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 1rem; align-items: start; }
        .cu-col { display: grid; gap: 1rem; min-width: 0; max-width: 100%; grid-template-columns: minmax(0, 1fr); }
        .cu-grid, .cu-col > *, .cu-col .fi-section { min-width: 0; max-width: 100%; }
        .cu-col .fi-section-content { overflow-wrap: anywhere; }
        @media (max-width: 768px) { .cu-grid { grid-template-columns: minmax(0, 1fr); } }
        .cu-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .cu-table th, .cu-table td { padding: .3rem .4rem; text-align: left; border-bottom: 1px solid rgba(128,128,128,.25); vertical-align: top; }
        .cu-table th { font-weight: 600; white-space: nowrap; }
        .cu-table tbody tr:last-child td { border-bottom: 0; }
        .cu-scroll { overflow-x: auto; }
        .cu-wide { min-width: 30rem; }
        .cu-right { text-align: right; white-space: nowrap; }
        .cu-facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: .2rem .75rem; font-size: .875rem; margin: 0; }
        .cu-facts dt { opacity: .7; }
        .cu-facts dd { margin: 0; overflow-wrap: anywhere; }
        .cu-muted { opacity: .7; font-size: .875rem; }
        .cu-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .35rem; font-size: .875rem; }
        .cu-link { text-decoration: underline; font-weight: 500; }
        .cu-note textarea { width: 100%; min-height: 6rem; font-size: .875rem; }
        .cu-cod-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .4rem; padding: .35rem 0; border-bottom: 1px solid rgba(128,128,128,.25); font-size: .875rem; }
        .cu-cod-row:last-child { border-bottom: 0; }
        .cu-cod-row small { display: block; opacity: .75; }
        .cu-title { display: inline-flex; align-items: center; }
    </style>

    <div class="cu-badges">
        <x-filament::badge :color="$d['person']['has_account'] ? 'success' : 'gray'">{{ $d['person']['has_account'] ? __('customers.badges.account') : __('customers.badges.guest') }}</x-filament::badge>
    </div>

    @if (! $d['privacy_ready'] && $mayPrivacy)
        <div class="cu-strip" role="alert">{{ __('customers.page.privacy_unavailable') }}</div>
    @endif

    @if ($confirmed)
        <div class="cu-strip cu-strip-confirmed" role="status">
            <strong>{{ __('customers.strip.confirmed', ['kind' => $kindLabel($confirmed->kind)]) }}</strong>
            <span class="cu-muted">·
                @if ($confirmed->staff_name)
                    {{ __('customers.strip.confirmed_detail', ['time' => $when($confirmed->confirmed_at), 'name' => $confirmed->staff_name]) }}
                @else
                    {{ __('customers.strip.confirmed_detail_no_staff', ['time' => $when($confirmed->confirmed_at)]) }}
                @endif
            </span>
        </div>
    @endif

    @if ($awaiting)
        <div class="cu-strip" role="status">
            {{ __('customers.strip.awaiting', ['kind' => mb_strtolower($kindLabel($awaiting->kind)), 'date' => $when($awaiting->created_at->copy()->addDays(\App\Privacy\PrivacyConfirmationRequester::VALID_DAYS))]) }}
        </div>
    @endif

    <div class="cu-grid">
        <div class="cu-col">
            <x-filament::section compact :heading="__('customers.sections.data')">
                <dl class="cu-facts">
                    <dt>{{ __('customers.data.name') }}</dt><dd>{{ $d['person']['name'] ?? '—' }}</dd>
                    <dt>{{ __('customers.data.email') }}</dt><dd>{{ $d['person']['email'] }}</dd>
                    <dt>{{ __('customers.data.phone') }}</dt><dd>{{ $d['person']['phone'] ?? '—' }}</dd>
                    <dt>{{ __('customers.data.account_since') }}</dt><dd>{{ $d['person']['account_since'] ? $when($d['person']['account_since'], 'd.m.Y') : '—' }}</dd>
                    <dt>{{ __('customers.data.spent') }}</dt><dd>{{ $d['spent'] ?? '—' }}</dd>
                </dl>
            </x-filament::section>

            <x-filament::section compact :heading="__('customers.sections.addresses')">
                @if ($d['addresses'] === [])
                    <p class="cu-muted">{{ __('customers.addresses.none') }}</p>
                @else
                    <ul class="cu-list">
                        @foreach ($d['addresses'] as $address)
                            <li>
                                <strong>{{ $address['name'] }}</strong>
                                @if ($address['default'])<x-filament::badge size="sm">{{ __('customers.addresses.default') }}</x-filament::badge>@endif
                                <div>{{ implode(', ', $address['lines']) }}</div>
                                @if ($address['phone'])<div class="cu-muted">{{ $address['phone'] }}</div>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section compact :heading="__('customers.sections.orders').' ('.trans_choice('customers.orders.count', $d['orders_count'], ['count' => $d['orders_count']]).')'">
                @if ($d['orders'] === [])
                    <p class="cu-muted">{{ __('customers.orders.none') }}</p>
                @else
                    <div class="cu-scroll">
                        <table class="cu-table">
                            <thead>
                                <tr>
                                    <th>{{ __('customers.orders.number') }}</th>
                                    <th>{{ __('customers.orders.date') }}</th>
                                    <th>{{ __('customers.orders.status') }}</th>
                                    <th class="cu-right">{{ __('customers.orders.total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($d['orders'] as $order)
                                    <tr>
                                        <td><a class="cu-link" href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order['id']]) }}">#{{ $order['id'] }}</a></td>
                                        <td>{{ $when($order['placed_at']) }}</td>
                                        <td><x-filament::badge size="sm" :color="$order['status_color']">{{ $order['status_label'] }}</x-filament::badge></td>
                                        <td class="cu-right">{{ $order['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($d['orders_count'] > count($d['orders']))
                        <p class="cu-muted">{{ __('customers.orders.latest', ['shown' => count($d['orders'])]) }}</p>
                    @endif
                @endif
            </x-filament::section>

            <x-filament::section compact :heading="__('customers.sections.consents')">
                @if ($d['consents'] === [])
                    <p class="cu-muted">{{ __('customers.consents.none') }}</p>
                @else
                    <ul class="cu-list">
                        @foreach ($d['consents'] as $consent)
                            <li>
                                <strong>{{ __('customers.consents.order', ['id' => $consent['order']]) }}</strong>
                                <span class="cu-muted">{{ $when($consent['placed_at'] ?? null, 'd.m.Y') }}</span>
                                <div>{{ ($consent['marketing_consent'] ?? false) ? __('customers.consents.marketing_yes') : __('customers.consents.marketing_no') }}@if (! empty($consent['marketing_consent_text'])) — «{{ $consent['marketing_consent_text'] }}»@endif</div>
                                @if (! empty($consent['terms_accepted_at']))
                                    <div class="cu-muted">
                                        @if (! empty($consent['terms_version']))
                                            {{ __('customers.consents.terms', ['date' => $when($consent['terms_accepted_at'], 'd.m.Y'), 'version' => $consent['terms_version']]) }}
                                        @else
                                            {{ __('customers.consents.terms_no_version', ['date' => $when($consent['terms_accepted_at'], 'd.m.Y')]) }}
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section compact :heading="__('customers.sections.lists')">
                @if ($d['lists'] === [])
                    <p class="cu-muted">{{ __('customers.lists.empty') }}</p>
                @else
                    @foreach ($d['lists'] as $list)
                        <p><strong>{{ $list['title'] }}</strong></p>
                        <ul class="cu-list">
                            @foreach ($list['items'] as $item)<li>{{ $item }}</li>@endforeach
                        </ul>
                    @endforeach
                @endif
            </x-filament::section>

            @if ($d['privacy_ready'])
                <div @if ($d['processing']) wire:poll.3s @endif>
                    <x-filament::section compact :heading="__('customers.sections.requests')">
                        @if ($d['requests']->isEmpty())
                            <p class="cu-muted">{{ __('customers.requests.none') }}</p>
                        @else
                            <div class="cu-scroll">
                                <table class="cu-table cu-wide">
                                    <thead>
                                        <tr>
                                            <th>{{ __('customers.requests.columns.request') }}</th>
                                            <th>{{ __('customers.requests.columns.status') }}</th>
                                            <th>{{ __('customers.requests.columns.dates') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($d['requests'] as $row)
                                            @php
                                                $downloadable = $mayPrivacy && $row->kind === 'export' && $row->status === 'done' && $row->export_path && $row->export_expires_at && $row->export_expires_at->isFuture();
                                                $waiting = $row->status === 'awaiting_confirmation' && $row->created_at->copy()->addDays(\App\Privacy\PrivacyConfirmationRequester::VALID_DAYS)->isFuture();
                                            @endphp
                                            <tr>
                                                <td>
                                                    {{ $kindLabel($row->kind) }}
                                                    @if ($row->staff_name)<div class="cu-muted">{{ __('customers.requests.by', ['name' => $row->staff_name]) }}</div>@endif
                                                </td>
                                                <td>{{ __('customers.requests.status.'.$row->status) }}</td>
                                                <td>
                                                    <div>{{ __('customers.requests.asked', ['date' => $when($row->created_at)]) }}</div>
                                                    @if ($row->export_expires_at && $row->status === 'done')<div class="cu-muted">{{ __('customers.requests.expires', ['date' => $when($row->export_expires_at, 'd.m.Y')]) }}</div>@endif
                                                </td>
                                                <td class="cu-right">
                                                    @if ($downloadable)
                                                        <a class="cu-link" href="{{ route('filament.admin.privacy.exports.download', ['uuid' => pathinfo($row->export_path, PATHINFO_FILENAME)]) }}">{{ __('customers.requests.download') }}</a>
                                                    @elseif ($waiting && $mayPrivacy)
                                                        {{ ($this->resendAction)(['id' => $row->id]) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-filament::section>
                </div>
            @endif
        </div>

        <div class="cu-col">
            <x-filament::section compact>
                <x-slot name="heading"><span class="cu-title">{{ __('customers.sections.note') }}{!! InfoHint::make(__('customers.note.hint'), 'customer-note') !!}</span></x-slot>
                <form wire:submit="saveNote" class="cu-note">
                    <textarea wire:model="noteBody" maxlength="2000" rows="5" aria-label="{{ __('customers.note.label') }}">{{ $noteBody }}</textarea>
                    <div style="margin-top: .5rem">
                        <x-filament::button type="submit" size="sm">{{ __('customers.note.save') }}</x-filament::button>
                    </div>
                </form>
            </x-filament::section>

            <x-filament::section compact>
                <x-slot name="heading"><span class="cu-title">{{ __('customers.sections.cod') }}{!! InfoHint::make(__('blocks.form.note'), 'customer-cod') !!}</span></x-slot>
                @php
                    $state = function (?array $block): string {
                        if ($block === null) { return __('customers.cod.none'); }
                        return $block['forever'] ? __('customers.cod.forever') : __('customers.cod.until', ['date' => \Illuminate\Support\Carbon::parse($block['until'])->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('d.m.Y')]);
                    };
                @endphp
                <div class="cu-cod-row">
                    <div>
                        <strong>{{ __('customers.cod.email') }}</strong>
                        <small>{{ $state($d['blocks']['email']) }}</small>
                        @if ($d['blocks']['email'] && $d['blocks']['email']['reason'])<small>{{ __('customers.cod.reason', ['reason' => $d['blocks']['email']['reason']]) }}</small>@endif
                        @if ($d['blocks']['email'] && $d['blocks']['email']['order'])<small>{{ __('customers.cod.order', ['id' => '#'.$d['blocks']['email']['order']]) }}</small>@endif
                    </div>
                    @if ($mayBlock)
                        {{ $d['blocks']['email'] ? ($this->unblockAction)(['type' => 'email']) : ($this->blockAction)(['type' => 'email']) }}
                    @endif
                </div>
                @forelse ($d['blocks']['phones'] as $phone)
                    <div class="cu-cod-row">
                        <div>
                            <strong>{{ $phone['display'] }}</strong>
                            @if ($phone['key'] === null)
                                <small>{{ __('customers.cod.no_key') }}</small>
                            @else
                                <small>{{ $state($phone['block']) }}</small>
                                @if ($phone['block'] && $phone['block']['reason'])<small>{{ __('customers.cod.reason', ['reason' => $phone['block']['reason']]) }}</small>@endif
                                @if ($phone['block'] && $phone['block']['order'])<small>{{ __('customers.cod.order', ['id' => '#'.$phone['block']['order']]) }}</small>@endif
                            @endif
                        </div>
                        @if ($mayBlock && $phone['key'] !== null)
                            {{ $phone['block'] ? ($this->unblockAction)(['type' => 'phone', 'key' => $phone['key']]) : ($this->blockAction)(['type' => 'phone', 'key' => $phone['key']]) }}
                        @endif
                    </div>
                @empty
                    <p class="cu-muted">{{ __('customers.cod.no_phones') }}</p>
                @endforelse
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
