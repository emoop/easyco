{{-- ACC-3b-1: the account menu: Табло · Поръчки · Адреси · Данни · Изход. $current: dashboard | orders | addresses | details. The current item has aria-current="page"; «Изход» is the ACC-3a sign-out (a POST to the API by storefront-account.js). Desktop: a column on the left; phone: a list at the top. --}}
<nav class="sf-acc-menu" aria-label="{{ __('account_pages.menu_label') }}">
    <ul class="sf-acc-menu-list">
        @foreach (['dashboard' => ['storefront.account', 'menu_dashboard'], 'orders' => ['storefront.account.orders', 'menu_orders'], 'addresses' => ['storefront.account.addresses', 'menu_addresses'], 'details' => ['storefront.account.details', 'menu_details']] as $key => [$route, $label])
            <li class="sf-acc-menu-item">
                <a class="sf-acc-menu-link" href="{{ route($route, [], false) }}" @if ($current === $key) aria-current="page" @endif>{{ __('account_pages.'.$label) }}</a>
            </li>
        @endforeach
        <li class="sf-acc-menu-item">
            <form data-sf-account-logout-form>
                <button class="sf-acc-menu-link sf-acc-menu-logout" type="submit" data-sf-account-submit>{{ __('account_pages.logout') }}</button>
            </form>
        </li>
    </ul>
</nav>
