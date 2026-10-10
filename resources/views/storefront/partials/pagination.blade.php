@if ($pagination['last'] > 1)
    <nav class="sf-pagination" aria-label="{{ __('storefront.pagination') }}">
        <p class="sf-pagination-status">{{ __('storefront.page_of', ['page' => $pagination['current'], 'last' => $pagination['last']]) }}</p>
        @if ($pagination['prev'] !== null)
            <a class="sf-pagination-prev" rel="prev" href="{{ $pagination['prev'] }}">{{ __('storefront.previous') }}</a>
        @endif
        <ol class="sf-pagination-pages">
            @foreach ($pagination['pages'] as $page)
                <li class="sf-pagination-page">
                    @if ($page['current'])
                        <span aria-current="page">{{ $page['number'] }}</span>
                    @else
                        <a href="{{ $page['url'] }}">{{ $page['number'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($pagination['next'] !== null)
            <a class="sf-pagination-next" rel="next" href="{{ $pagination['next'] }}">{{ __('storefront.next') }}</a>
        @endif
    </nav>
@endif
