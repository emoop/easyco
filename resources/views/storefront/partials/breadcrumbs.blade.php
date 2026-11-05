<nav class="sf-breadcrumbs" aria-label="{{ __('storefront.breadcrumbs') }}">
    <ol class="sf-breadcrumbs-list">
        <li class="sf-breadcrumb"><a href="{{ route('storefront.home', [], false) }}">{{ __('storefront.home') }}</a></li>
        @foreach ($crumbs as $crumb)
            @if ($loop->last)
                <li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">{{ $crumb->label }}</li>
            @elseif ($crumb->url === '')
                {{-- P2: a step with no page of its own (the «Марки» of a brand page) is plain text --}}
                <li class="sf-breadcrumb">{{ $crumb->label }}</li>
            @else
                <li class="sf-breadcrumb"><a href="{{ $crumb->url }}">{{ $crumb->label }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
