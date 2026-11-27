<?php

namespace App\Rules;

use App\Support\BgPostcodes;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The postcode of a delivery to an ADDRESS (COUR-2a.5). Bulgaria has many villages and small towns with the same name in different regions, so the name
 * alone cannot identify the place; the postcode (with the region) does. The only format known today is Bulgaria's: four digits. For any other country
 * nothing is invented: a non-empty value is enough (the required rule, not this one, refuses an empty one). The country is the value of `$countryField`
 * in the same request, already trimmed and upper-cased by the controller.
 */
final class DeliveryPostalCode implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $countryField = 'country')
    {
    }

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $country = $this->data[$this->countryField] ?? null;

        if ($country === 'BG' && ! BgPostcodes::isPostcode($value)) {
            $fail('storefront.checkout_postal_code_invalid')->translate();
        }
    }
}
