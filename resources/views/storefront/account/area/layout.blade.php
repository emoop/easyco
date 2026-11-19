@extends('storefront.layout')

{{-- ACC-3b-1: the shell of every page of the account area: the shop's own breadcrumbs + sf-title h1, then the menu (left on a desktop, a list at the top on a phone) and the page. data-sf-account="home" is what storefront-account.js binds the sign-out forms on. --}}
@php
    $profile = (string) __('account_pages.title');
    $trail = match ($current) {
        'dashboard' => [new \App\Storefront\ReadModels\Breadcrumb($profile, '')],
        'orders' => isset($number)
            ? [new \App\Storefront\ReadModels\Breadcrumb($profile, route('storefront.account', [], false)), new \App\Storefront\ReadModels\Breadcrumb((string) __('account_pages.orders_title'), route('storefront.account.orders', [], false)), new \App\Storefront\ReadModels\Breadcrumb('№ '.$number, '')]
            : [new \App\Storefront\ReadModels\Breadcrumb($profile, route('storefront.account', [], false)), new \App\Storefront\ReadModels\Breadcrumb((string) __('account_pages.orders_title'), '')],
        'addresses' => isset($formPage)
            ? [new \App\Storefront\ReadModels\Breadcrumb($profile, route('storefront.account', [], false)), new \App\Storefront\ReadModels\Breadcrumb((string) __('account_pages.addresses_title'), route('storefront.account.addresses', [], false)), new \App\Storefront\ReadModels\Breadcrumb($title, '')]
            : [new \App\Storefront\ReadModels\Breadcrumb($profile, route('storefront.account', [], false)), new \App\Storefront\ReadModels\Breadcrumb($title, '')],
        default => [new \App\Storefront\ReadModels\Breadcrumb($profile, route('storefront.account', [], false)), new \App\Storefront\ReadModels\Breadcrumb($title, '')],
    };
@endphp

@section('content')
    <section class="sf-account-area" data-sf-account="home">
        @include('storefront.account.partials.head', ['title' => $title, 'crumbs' => $trail, 'plainTitle' => true])
        <div class="sf-acc-layout">
            @include('storefront.account.partials.menu', ['current' => $current])
            <div class="sf-acc-main">
                @if (($notice ?? null) !== null)
                    <p class="sf-account-notice" role="status">{{ $notice }}</p>
                @endif
                @yield('account')
            </div>
        </div>
    </section>
@endsection
