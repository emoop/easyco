{{-- Footer block `payments` (FT-1): small inline marks of the chosen methods (a closed list), each with an accessible name. See payment-icon for which marks are drawn and which are neutral badges. --}}
<ul class="sf-ft-block sf-ft-pay" aria-label="{{ __('footer.shop.payments') }}">
    @foreach ($settings['methods'] as $method)
        <li class="sf-ft-pay-item">
            @include('storefront.footer.payment-icon', ['method' => $method])
            <span class="sf-sr-only">{{ __('footer.payments.'.$method) }}</span>
        </li>
    @endforeach
</ul>
