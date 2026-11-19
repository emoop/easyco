{{-- ACC-3a: the email field. $id. --}}
<div class="sf-field sf-float">
    <label for="{{ $id }}">{{ __('account_pages.email') }}</label>
    <input id="{{ $id }}" name="email" type="email" autocomplete="email" inputmode="email" required aria-required="true" maxlength="254" placeholder=" ">
    <p class="sf-field-error" data-sf-error-for="email" hidden></p>
</div>
