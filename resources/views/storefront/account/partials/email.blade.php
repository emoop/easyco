{{-- ACC-3a: the email field. $id; $label and $autocomplete (optional: the «new email» of the details page is not an autofill of the current one). --}}
<div class="sf-field sf-float">
    <label for="{{ $id }}">{{ $label ?? __('account_pages.email') }}</label>
    <input id="{{ $id }}" name="email" type="email" autocomplete="{{ $autocomplete ?? 'email' }}" inputmode="email" required aria-required="true" maxlength="254" placeholder=" ">
    <p class="sf-field-error" data-sf-error-for="email" hidden></p>
</div>
