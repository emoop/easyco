<?php

namespace EasyCo\Shipping;

use EasyCo\Shipping\Exceptions\InvalidShippingZoneException;
use LogicException;

/**
 * An ordered region a delivery address can fall in — shipping-domain-design.md
 * §4. STORE ONLY IN THIS STAGE: countryCodes, settlementNames and postcodes
 * are validated and persisted, but nothing here matches an address against
 * them — zone matching (first match by sortOrder wins, exactly one zone per
 * order) is stage 3a's.
 *
 * countryCodes: a non-empty list of unique uppercase two-letter codes. They
 * are NOT checked against an official country list — only against the shape.
 * settlementNames: null, or a list of unique, trimmed, non-empty strings,
 * STORED AS ENTERED — the locale-aware normalization used for matching
 * ("гр. София" against "София") belongs to stage 3a's SettlementNameNormalizer
 * and is deliberately not applied here.
 * postcodes: null, or a list normalized AT CONSTRUCTION — trimmed, ALL
 * whitespace removed, uppercased ("sw1a 1aa" becomes "SW1A1AA") — then checked
 * against ^[A-Z0-9-]{2,12}$; duplicates after normalization are refused.
 * For both lists an empty list means "no narrowing" and normalizes to null.
 * The two lists replaced the single free-form `settlementPatterns` list, which
 * mixed names and postcodes and could not tell them apart.
 *
 * sortOrder is a non-negative int and is NOT unique; every ordered read uses
 * `sortOrder ASC, id ASC`.
 */
final class ShippingZone
{
    public const NAME_MAX_LENGTH = 255;

    private string $name;

    private int $sortOrder;

    /** @var list<string> */
    private array $countryCodes;

    /** @var list<string>|null */
    private ?array $settlementNames;

    /** @var list<string>|null */
    private ?array $postcodes;

    /**
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementNames
     * @param  list<string>|null  $postcodes
     */
    private function __construct(
        private ?string $id,
        string $name,
        int $sortOrder,
        array $countryCodes,
        ?array $settlementNames,
        ?array $postcodes,
    ) {
        $this->apply($name, $sortOrder, $countryCodes, $settlementNames, $postcodes);
    }

    /**
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementNames
     * @param  list<string>|null  $postcodes
     */
    public static function create(string $name, int $sortOrder, array $countryCodes, ?array $settlementNames = null, ?array $postcodes = null): self
    {
        return new self(null, $name, $sortOrder, $countryCodes, $settlementNames, $postcodes);
    }

    /**
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the data
     * is already-valid data read back from storage.
     *
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementNames
     * @param  list<string>|null  $postcodes
     */
    public static function reconstituteFromStorage(string $id, string $name, int $sortOrder, array $countryCodes, ?array $settlementNames, ?array $postcodes): self
    {
        return new self($id, $name, $sortOrder, $countryCodes, $settlementNames, $postcodes);
    }

    /**
     * Rewrites every editable field, re-running the construction-time
     * validation — never a looser rule set for updates (same shape as
     * Address::update()).
     *
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementNames
     * @param  list<string>|null  $postcodes
     */
    public function update(string $name, int $sortOrder, array $countryCodes, ?array $settlementNames = null, ?array $postcodes = null): void
    {
        $this->apply($name, $sortOrder, $countryCodes, $settlementNames, $postcodes);
    }

    /**
     * Validates everything first and assigns last, so a rejected update leaves
     * the entity exactly as it was.
     *
     * @param  array<mixed>  $countryCodes
     * @param  array<mixed>|null  $settlementNames
     * @param  array<mixed>|null  $postcodes
     */
    private function apply(string $name, int $sortOrder, array $countryCodes, ?array $settlementNames, ?array $postcodes): void
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidShippingZoneException::emptyName();
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw InvalidShippingZoneException::nameTooLong(self::NAME_MAX_LENGTH);
        }

        if ($sortOrder < 0) {
            throw InvalidShippingZoneException::negativeSortOrder($sortOrder);
        }

        $countryCodes = self::normalizeCountryCodes($countryCodes);
        $settlementNames = self::normalizeSettlementNames($settlementNames);
        $postcodes = self::normalizePostcodes($postcodes);

        $this->name = $name;
        $this->sortOrder = $sortOrder;
        $this->countryCodes = $countryCodes;
        $this->settlementNames = $settlementNames;
        $this->postcodes = $postcodes;
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<string>
     */
    private static function normalizeCountryCodes(array $codes): array
    {
        if ($codes === []) {
            throw InvalidShippingZoneException::noCountryCodes();
        }

        $seen = [];

        foreach ($codes as $code) {
            if (! is_string($code) || preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
                throw InvalidShippingZoneException::invalidCountryCode($code);
            }

            if (isset($seen[$code])) {
                throw InvalidShippingZoneException::duplicateCountryCode($code);
            }

            $seen[$code] = true;
        }

        return array_keys($seen);
    }

    /**
     * @param  array<mixed>|null  $names
     * @return list<string>|null
     */
    private static function normalizeSettlementNames(?array $names): ?array
    {
        if ($names === null || $names === []) {
            return null;
        }

        $seen = [];

        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                throw InvalidShippingZoneException::invalidSettlementName($name);
            }

            $name = trim($name);

            if (isset($seen[$name])) {
                throw InvalidShippingZoneException::duplicateSettlementName($name);
            }

            $seen[$name] = true;
        }

        // array_keys() would turn a numeric-looking name into an int key, so
        // the keys are cast back to strings.
        return array_map('strval', array_keys($seen));
    }

    /**
     * @param  array<mixed>|null  $postcodes
     * @return list<string>|null
     */
    private static function normalizePostcodes(?array $postcodes): ?array
    {
        if ($postcodes === null || $postcodes === []) {
            return null;
        }

        $seen = [];

        foreach ($postcodes as $postcode) {
            if (! is_string($postcode)) {
                throw InvalidShippingZoneException::invalidPostcode($postcode);
            }

            // trim, remove ALL whitespace (ASCII and Unicode separators), uppercase
            $normalized = mb_strtoupper((string) preg_replace('/[\s\p{Z}]+/u', '', $postcode));

            if (preg_match('/^[A-Z0-9-]{2,12}$/D', $normalized) !== 1) {
                throw InvalidShippingZoneException::invalidPostcode($postcode);
            }

            if (isset($seen[$normalized])) {
                throw InvalidShippingZoneException::duplicatePostcode($normalized);
            }

            $seen[$normalized] = true;
        }

        return array_map('strval', array_keys($seen));
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ShippingZone already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /** @return list<string> */
    public function countryCodes(): array
    {
        return $this->countryCodes;
    }

    /** @return list<string>|null as entered, trimmed */
    public function settlementNames(): ?array
    {
        return $this->settlementNames;
    }

    /** @return list<string>|null normalized: no whitespace, uppercase */
    public function postcodes(): ?array
    {
        return $this->postcodes;
    }
}
