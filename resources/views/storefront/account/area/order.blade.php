@extends('storefront.account.area.layout')

@section('account')
    <dl class="sf-received-facts">
        <div class="sf-received-fact">
            <dt>{{ __('storefront.received_number') }}</dt>
            <dd>{{ $number }}</dd>
        </div>
        <div class="sf-received-fact">
            <dt>{{ __('storefront.received_date') }}</dt>
            <dd>{{ $placed }}</dd>
        </div>
        <div class="sf-received-fact">
            <dt>{{ __('account_pages.col_status') }}</dt>
            <dd>{{ $status }}</dd>
        </div>
        @if ($tracking !== null)
            <div class="sf-received-fact">
                <dt>{{ __('account_pages.order_tracking') }}</dt>
                <dd>{{ $tracking['courier'] !== null ? $tracking['courier'].' / ' : '' }}{{ $tracking['number'] }}</dd>
            </div>
        @endif
    </dl>

    {{-- the SAME body as the order-received page (items, totals, delivery, note, payment): one partial --}}
    <div class="sf-received">
        @include('storefront.partials.order-body')
    </div>

    <p class="sf-acc-back"><a href="{{ route('storefront.account.orders', [], false) }}">{{ __('account_pages.back_to_orders') }}</a></p>
@endsection
