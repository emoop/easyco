@extends('storefront.account.area.layout')

{{-- ONE markup for the table (desktop) and the cards (phone): the layout changes in CSS only (≤ 40rem). --}}
@section('account')
    @if ($rows === [])
        <div class="sf-acc-empty">
            <p class="sf-acc-empty-title">{{ __('account_pages.orders_empty') }}</p>
            <a class="sf-place-order sf-acc-empty-button" href="{{ route('storefront.home', [], false) }}">{{ __('account_pages.orders_empty_link') }}</a>
        </div>
    @else
        <table class="sf-acc-orders">
            <caption class="sf-sr-only">{{ __('account_pages.orders_title') }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ __('account_pages.col_order') }}</th>
                    <th scope="col">{{ __('account_pages.col_date') }}</th>
                    <th scope="col">{{ __('account_pages.col_status') }}</th>
                    <th scope="col">{{ __('account_pages.col_total') }}</th>
                    <th scope="col"><span class="sf-sr-only">{{ __('account_pages.view_order') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="sf-acc-order">
                        <th scope="row" class="sf-acc-order-number">№ {{ $row['number'] }}</th>
                        <td class="sf-acc-order-date">{{ $row['date'] }}</td>
                        <td class="sf-acc-order-status">{{ $row['status'] }}</td>
                        <td class="sf-acc-order-total">{{ $row['total'] }} <span class="sf-acc-order-count">{{ trans_choice('account_pages.orders_for_items', $row['items'], ['count' => $row['items']]) }}</span></td>
                        <td class="sf-acc-order-view"><a class="sf-acc-order-link" href="{{ $row['url'] }}">{{ __('account_pages.view_order') }}<span class="sf-sr-only"> № {{ $row['number'] }}</span></a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($newer !== null || $older !== null)
            <nav class="sf-acc-pager" aria-label="{{ __('account_pages.pager_label') }}">
                @if ($newer !== null)
                    <a class="sf-acc-pager-link" href="{{ $newer }}" rel="prev">{{ __('account_pages.pager_newer') }}</a>
                @endif
                <span class="sf-acc-pager-page">{{ __('account_pages.pager_page', ['page' => $meta['current_page'], 'last' => $meta['last_page']]) }}</span>
                @if ($older !== null)
                    <a class="sf-acc-pager-link" href="{{ $older }}" rel="next">{{ __('account_pages.pager_older') }}</a>
                @endif
            </nav>
        @endif
    @endif
@endsection
