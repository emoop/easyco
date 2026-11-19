@extends('storefront.account.area.layout')

{{-- ACC-3b-2: the address book, read on the server. The writes (delete, make default) are buttons storefront-account.js sends to the API; «Изтрий» asks once, inline (no window.confirm, no modal). --}}
@section('account')
    @if ($cards === [])
        <div class="sf-acc-empty">
            <p class="sf-acc-empty-title">{{ __('account_pages.addresses_empty') }}</p>
            <a class="sf-place-order sf-acc-empty-button" href="{{ route('storefront.account.address.new', [], false) }}">{{ __('account_pages.address_add') }}</a>
        </div>
    @else
        <ul class="sf-acc-addresses">
            @foreach ($cards as $card)
                <li class="sf-acc-address" data-sf-address data-address-id="{{ $card['id'] }}">
                    <div class="sf-acc-address-head">
                        <p class="sf-acc-address-name">{{ $card['name'] }}</p>
                        @if ($card['default'])
                            <span class="sf-acc-badge">{{ __('account_pages.address_default_badge') }}</span>
                        @endif
                    </div>
                    <p class="sf-acc-address-phone">{{ $card['phone'] }}</p>
                    <address class="sf-acc-address-lines">
                        @if ($card['kind'] !== null)
                            <span class="sf-acc-address-line sf-acc-address-kind">{{ $card['courier'] !== null ? $card['courier'].' / ' : '' }}{{ $card['kind'] === 'locker' ? __('account_pages.address_kind_locker') : __('account_pages.address_kind_office') }}</span>
                        @elseif ($card['courier'] !== null)
                            <span class="sf-acc-address-line sf-acc-address-kind">{{ __('account_pages.address_courier') }}: {{ $card['courier'] }}</span>
                        @endif
                        @foreach ($card['lines'] as $line)
                            <span class="sf-acc-address-line">{{ $line }}</span>
                        @endforeach
                    </address>

                    <div class="sf-acc-address-actions" data-sf-address-actions>
                        @if ($card['editUrl'] !== null)
                            <a class="sf-acc-btn" href="{{ $card['editUrl'] }}">{{ __('account_pages.address_edit') }}</a>
                        @endif
                        @if (! $card['default'])
                            <button class="sf-acc-btn" type="button" data-sf-address-default>{{ __('account_pages.address_make_default') }}</button>
                        @endif
                        <button class="sf-acc-btn sf-acc-btn-danger" type="button" data-sf-address-delete>{{ __('account_pages.address_delete') }}</button>
                    </div>
                    <div class="sf-acc-address-actions" data-sf-address-confirm hidden>
                        <p class="sf-acc-address-question" role="group">{{ __('account_pages.address_delete_question') }}</p>
                        <button class="sf-acc-btn sf-acc-btn-danger" type="button" data-sf-address-delete-yes>{{ __('account_pages.address_delete_yes') }}</button>
                        <button class="sf-acc-btn" type="button" data-sf-address-delete-no>{{ __('account_pages.address_delete_no') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($full)
            <p class="sf-account-intro sf-acc-full">{{ __('account_pages.addresses_full', ['max' => $max]) }}</p>
        @else
            <p class="sf-acc-add"><a class="sf-place-order sf-acc-add-button" href="{{ route('storefront.account.address.new', [], false) }}">{{ __('account_pages.address_add') }}</a></p>
        @endif
    @endif
@endsection
