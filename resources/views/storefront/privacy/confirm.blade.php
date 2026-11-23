@extends('storefront.layout')

@section('content')
    {{-- ACC-4d: the identity confirmation of a privacy request. NO email, NO order data, nothing about the request but its type. A GET changes nothing (mail scanners open links):
         the button POSTs to this same address (the form has no action, so the signed parameters in the address go with it and the HTML never holds them). --}}
    <section class="sf-account" data-sf-privacy-confirm="{{ $state }}">
        <h1 class="sf-title sf-account-title">{{ $heading }}</h1>
        @if ($state === 'form')
            <p class="sf-account-intro">{{ __('privacy_confirm.intro_'.$kind) }}</p>
            <form class="sf-account-form" method="post">
                <button class="sf-place-order" type="submit">{{ __('privacy_confirm.button') }}</button>
            </form>
            <p class="sf-account-intro">{{ __('privacy_confirm.note') }}</p>
        @elseif ($state === 'done')
            <p class="sf-account-intro">{{ __('privacy_confirm.done_text') }}</p>
        @else
            <p class="sf-account-intro">{{ __('privacy_confirm.invalid_text') }}</p>
        @endif
    </section>
@endsection
