<nav class="sf-breadcrumbs" aria-label="{{ __('storefront.breadcrumbs') }}">
    <ol class="sf-breadcrumbs-list">
        <li class="sf-breadcrumb"><a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a></li>
        @foreach ($crumbs as $crumb)
            @if ($loop->last)
                <li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">{{ $crumb->label }}</li>
            @else
                <li class="sf-breadcrumb"><a href="{{ $crumb->url }}">{{ $crumb->label }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
