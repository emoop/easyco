@extends('storefront.layout')

@section('content')
    <section class="sf-received">
        <h1 class="sf-title">{{ __('storefront.received_heading') }}</h1>
        <p class="sf-received-email">{{ __('storefront.received_email_sent', ['email' => $order['email']]) }}</p>

        <dl class="sf-received-facts">
            <div class="sf-received-fact">
                <dt>{{ __('storefront.received_number') }}</dt>
                <dd>{{ $order['number'] }}</dd>
            </div>
            <div class="sf-received-fact">
                <dt>{{ __('storefront.received_date') }}</dt>
                <dd>{{ $order['placed_at'] }}</dd>
            </div>
        </dl>

        <h2 class="sf-section-title">{{ __('storefront.received_items') }}</h2>
        <ul class="sf-received-lines">
            @foreach ($order['lines'] as $line)
                <li class="sf-received-line">
                    <span class="sf-received-line-name">{{ $line['name'] }}@if ($line['label'] !== '') <span class="sf-received-line-label">({{ $line['label'] }})</span>@endif</span>
                    <span class="sf-received-line-qty">{{ __('storefront.received_quantity') }}: {{ $line['quantity'] }}</span>
                    <span class="sf-received-line-total">{{ $line['total'] }}</span>
                </li>
            @endforeach
        </ul>

        <dl class="sf-cart-totals">
            <div class="sf-cart-total-row">
                <dt class="sf-cart-total-caption">{{ __('storefront.received_subtotal') }}</dt>
                <dd class="sf-cart-total-amount">{{ $order['subtotal'] }}</dd>
            </div>
            @if ($order['discount'] !== null)
                <div class="sf-cart-total-row sf-cart-total-discount">
                    <dt class="sf-cart-total-caption">{{ $order['discount']['code'] !== null ? __('storefront.received_discount_code', ['code' => $order['discount']['code']]) : __('storefront.received_discount') }}</dt>
                    <dd class="sf-cart-total-amount">-{{ $order['discount']['amount'] }}</dd>
                </div>
            @endif
            @if ($order['shipping'] !== null)
                <div class="sf-cart-total-row">
                    <dt class="sf-cart-total-caption">{{ __('storefront.received_shipping') }}: {{ $order['shipping']['name'] }}@if ($order['shipping']['courier'] !== null) / {{ $order['shipping']['courier'] }}@endif</dt>
                    <dd class="sf-cart-total-amount">{{ $order['shipping']['free'] ? __('storefront.received_free') : $order['shipping']['price'] }}</dd>
                </div>
            @endif
            <div class="sf-cart-total-row sf-cart-total-grand">
                <dt class="sf-cart-total-caption">{{ __('storefront.received_total') }}</dt>
                <dd class="sf-cart-total-amount">{{ $order['total'] }}</dd>
            </div>
        </dl>

        <h2 class="sf-section-title">{{ $order['destination']['kind'] === 'pickup_point' ? __('storefront.received_pickup_point') : __('storefront.received_destination') }}</h2>
        <address class="sf-received-destination">
            @foreach ($order['destination']['lines'] as $part)
                <span class="sf-received-destination-line">{{ $part }}</span>
            @endforeach
        </address>

        <h2 class="sf-section-title">{{ __('storefront.received_payment') }}</h2>
        @php($methodKey = 'storefront.payment_methods.'.$order['payment']['method'])
        <p class="sf-received-payment">{{ __($methodKey) === $methodKey ? $order['payment']['method'] : __($methodKey) }}</p>

        @if ($order['payment']['bank'] !== null)
            <section class="sf-bank-details" aria-labelledby="sf-bank-title">
                <h3 class="sf-section-title" id="sf-bank-title">{{ __('mail.bank_transfer.heading') }}</h3>
                <dl class="sf-bank-rows">
                    @foreach ($order['payment']['bank']['rows'] as [$caption, $value])
                        <div class="sf-bank-row">
                            <dt>{{ $caption }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($order['payment']['bank']['deadline_days'] !== null)
                    <p class="sf-bank-deadline">{{ __('mail.bank_transfer.deadline', ['days' => $order['payment']['bank']['deadline_days']]) }}</p>
                @endif
                @if ($order['payment']['bank']['instructions'] !== null)
                    <p class="sf-bank-instructions">{{ $order['payment']['bank']['instructions'] }}</p>
                @endif
            </section>
        @endif

        @if ($order['payment']['cash_on_delivery_due'] !== null)
            <p class="sf-cod-note">{{ __('mail.payment.cash_on_delivery_due', ['amount' => $order['payment']['cash_on_delivery_due']]) }}</p>
        @endif

    </section>
@endsection
