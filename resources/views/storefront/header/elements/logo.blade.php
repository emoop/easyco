{{-- The shop logo; its height is the CSS variable --sf-logo-d-h / --sf-logo-m-h of the token stylesheet (never an inline style). --}}
<a class="sf-logo sf-logo-{{ $context }}" href="{{ route('storefront.home', [], false) }}">
    <img src="{{ asset('images/logo.svg') }}" alt="{{ config('app.name') }}" width="596" height="150">
</a>
