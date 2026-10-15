<?php

namespace App\Rules;

use App\Services\PhoneNumbers;
use Closure;
use EasyCo\Address\Persistence\Eloquent\AddressModel;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number that is VALID in the country it is delivered to (stage PH-1; PhoneNumbers is the one reader). The country is the value of
 * `$countryField` in the same request; when `$addressField` is given and present, the SAVED address's country is used instead (the request then
 * carries no country). A number written with `+` or `00` may belong to any country. When the country itself is missing or unknown the country's
 * own rule reports it and this one stays silent for a national number. The message never says why a number failed beyond "check that no digit
 * is missing".
 */
final class ValidPhone implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string $countryField = 'country',
        private readonly ?string $addressField = null,
        private readonly ?string $fixedCountry = null,
        private readonly bool $mobileOnly = false,
    ) {
    }

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('checkout.phone_invalid')->translate();

            return;
        }

        $country = $this->country();
        $international = str_starts_with(ltrim($value), '+') || str_starts_with(ltrim($value), '00');

        if ($country === null && ! $international) {
            return;
        }

        $parsed = PhoneNumbers::parse($value, $country);

        if ($parsed === null) {
            $fail('checkout.phone_invalid')->translate();

            return;
        }

        // PH-2 (the merchant's option, storefront only): the courier's message goes to a mobile. FIXED_LINE_OR_MOBILE (a US number) passes.
        if ($this->mobileOnly && ! in_array($parsed->type, ['MOBILE', 'FIXED_LINE_OR_MOBILE'], true)) {
            $fail('checkout.phone_mobile_required')->translate();
        }
    }

    /** For a form that holds its own state (the admin's order editor): the country is the one the form shows, not a request field. */
    public static function forCountry(?string $country): self
    {
        return new self(fixedCountry: $country === null || $country === '' ? null : strtoupper($country));
    }

    private function country(): ?string
    {
        if ($this->fixedCountry !== null) {
            return $this->fixedCountry;
        }

        $addressId = $this->addressField === null ? null : ($this->data[$this->addressField] ?? null);

        if (is_string($addressId) && $addressId !== '') {
            $country = AddressModel::query()->whereKey($addressId)->value('country');

            return is_string($country) && $country !== '' ? strtoupper($country) : null;
        }

        $country = KnownCountryCode::normalize($this->data[$this->countryField] ?? null);

        return is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }
}
