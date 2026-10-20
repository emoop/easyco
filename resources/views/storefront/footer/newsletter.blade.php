{{-- Footer block `newsletter` (FT-1): printed ONLY while the subscription route exists (FooterView drops the block otherwise). An email field, a button and a consent line. The storefront has no session, so no CSRF token: the subscription stage must protect its endpoint another way (rate limit, double opt-in). --}}
<div class="sf-ft-block sf-ft-newsletter">
    <p class="sf-ft-heading" id="sf-ft-h-{{ $uid }}">{{ $settings['heading'] !== '' ? $settings['heading'] : __('footer.shop.newsletter_heading') }}</p>
    <form class="sf-ft-form" method="post" action="{{ route(\App\Storefront\Footer\FooterCatalog::NEWSLETTER_ROUTE, [], false) }}" aria-labelledby="sf-ft-h-{{ $uid }}">
        <label class="sf-sr-only" for="sf-ft-email-{{ $uid }}">{{ __('footer.shop.newsletter_email') }}</label>
        <input class="sf-ft-input" id="sf-ft-email-{{ $uid }}" name="email" type="email" maxlength="120" required autocomplete="email" placeholder="{{ $settings['placeholder'] !== '' ? $settings['placeholder'] : __('footer.shop.newsletter_placeholder') }}">
        <button class="sf-ft-button" type="submit">{{ $settings['button'] !== '' ? $settings['button'] : __('footer.shop.newsletter_button') }}</button>
    </form>
    @if ($settings['consent'] !== '')
        <p class="sf-ft-consent">{{ $settings['consent'] }}</p>
    @endif
</div>
