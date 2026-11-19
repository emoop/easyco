@extends('storefront.account.area.layout')

@section('account')
    <p class="sf-account-greeting">{{ __('account_pages.home_greeting', ['email' => $email]) }}</p>
    <p class="sf-account-intro">{{ __('account_pages.home_intro') }}</p>
    <ul class="sf-acc-tiles">
        @foreach (['orders' => ['storefront.account.orders', 'menu_orders'], 'addresses' => ['storefront.account.addresses', 'menu_addresses'], 'details' => ['storefront.account.details', 'menu_details']] as $icon => [$route, $label])
            <li class="sf-acc-tile">
                <a class="sf-acc-tile-link" href="{{ route($route, [], false) }}">
                    @include('storefront.account.partials.icon', ['name' => $icon])
                    <span class="sf-acc-tile-label">{{ __('account_pages.'.$label) }}</span>
                </a>
            </li>
        @endforeach
        <li class="sf-acc-tile">
            <form data-sf-account-logout-form>
                <button class="sf-acc-tile-link" type="submit" data-sf-account-submit>
                    @include('storefront.account.partials.icon', ['name' => 'logout'])
                    <span class="sf-acc-tile-label">{{ __('account_pages.logout') }}</span>
                </button>
            </form>
        </li>
    </ul>
@endsection
