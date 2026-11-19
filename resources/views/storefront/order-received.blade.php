@extends('storefront.layout')

@section('content')
    <section class="sf-received">
        <h1 class="sf-title">{{ __('storefront.received_heading') }}</h1>
        <p class="sf-received-greeting">{{ __('storefront.received_greeting', ['name' => $order['first_name']]) }}</p>
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

        @include('storefront.partials.order-body')

    </section>
@endsection
