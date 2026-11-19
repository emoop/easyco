{{-- ACC-3a: the top of every account page: breadcrumbs «Начало / Моят профил», the title, the script's texts (data attributes: no inline script) and the ONE aria-live region.
     $title: the page's title (h1). $crumbs (optional, the area pages): the trail after «Начало»; $plainTitle (optional): the shop's plain sf-title h1 with no account margin (ACC-3b-1). «Моят профил» is the current breadcrumb (a page of the account area is part of the profile). --}}
@include('storefront.partials.breadcrumbs', ['crumbs' => $crumbs ?? [new \App\Storefront\ReadModels\Breadcrumb((string) __('account_pages.title'), '')]])
<h1 class="sf-title{{ ($plainTitle ?? false) ? '' : ' sf-account-title' }}">{{ $title }}</h1>
<div hidden data-sf-account-i18n
     @foreach (trans('account_pages.js') as $key => $text)
         data-m-{{ str_replace('_', '-', $key) }}="{{ $text }}"
     @endforeach
     data-login-url="{{ route('storefront.account.login', [], false) }}"
     data-forgot-url="{{ route('storefront.account.forgot', [], false) }}"
     data-account-url="{{ route('storefront.account', [], false) }}"></div>
<noscript>
    <p class="sf-account-notice">{{ __('account_pages.noscript') }}</p>
</noscript>
<p class="sf-account-status" role="status" aria-live="polite" aria-atomic="true" data-sf-account-status hidden></p>
