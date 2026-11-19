@extends('storefront.layout')

@section('content')
    <section class="sf-account" data-sf-account="login" data-redirect="{{ $redirect }}">
        @include('storefront.account.partials.head', ['title' => __('account_pages.login_title')])
        @if ($notice !== null)
            <p class="sf-account-notice" role="status">{{ $notice }}</p>
        @endif
        <form class="sf-account-form" data-sf-account-form novalidate>
            @include('storefront.account.partials.email', ['id' => 'sf-acc-email'])
            @include('storefront.account.partials.password', ['id' => 'sf-acc-password', 'name' => 'password', 'label' => __('account_pages.password'), 'autocomplete' => 'current-password'])
            <label class="sf-choice sf-account-remember"><input type="checkbox" name="remember" value="1"> <span>{{ __('account_pages.remember') }}</span></label>
            <button class="sf-place-order" type="submit" data-sf-account-submit>{{ __('account_pages.login_submit') }}</button>
        </form>
        <div class="sf-account-resend" data-sf-account-resend hidden>
            <button class="sf-account-secondary" type="button" data-sf-account-resend-button>{{ __('account_pages.resend_button') }}</button>
        </div>
        <p class="sf-account-links">
            <a href="{{ route('storefront.account.forgot', [], false) }}">{{ __('account_pages.forgot_link') }}</a>
            <a href="{{ route('storefront.account.register', [], false) }}">{{ __('account_pages.register_link') }}</a>
        </p>
    </section>
@endsection
