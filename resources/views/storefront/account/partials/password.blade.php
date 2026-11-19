{{-- ACC-3a: a password field with the show / hide button. $id, $name, $label, $autocomplete; $hint (optional): the rules, tied to the input with aria-describedby. --}}
<div class="sf-field sf-float sf-account-password">
    <label for="{{ $id }}">{{ $label }}</label>
    <input id="{{ $id }}" name="{{ $name }}" type="password" autocomplete="{{ $autocomplete }}" required aria-required="true" maxlength="255" placeholder=" "
           @isset($hint) aria-describedby="{{ $id }}-hint" @endisset>
    <button class="sf-account-toggle" type="button" data-sf-password-toggle aria-controls="{{ $id }}" aria-pressed="false"
            data-show="{{ __('account_pages.show_password') }}" data-hide="{{ __('account_pages.hide_password') }}">{{ __('account_pages.show_password') }}</button>
    @isset($hint)
        <p class="sf-field-note" id="{{ $id }}-hint">{{ $hint }}</p>
    @endisset
    <p class="sf-field-error" data-sf-error-for="{{ $name }}" hidden></p>
</div>
