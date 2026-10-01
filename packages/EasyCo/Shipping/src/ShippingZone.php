<?php

namespace EasyCo\Shipping;

use EasyCo\Shipping\Exceptions\InvalidShippingZoneException;
use LogicException;

/**
 * An ordered region a delivery address can fall in — shipping-domain-design.md
 * §4. STORE ONLY IN THIS STAGE: countryCodes and settlementPatterns are
 * validated and persisted, but nothing here matches an address against them —
 * zone matching (first match by sortOrder wins, exactly one zone per order) is
 * stage 3's.
 *
 * countryCodes: a non-empty list of unique uppercase two-letter codes. They
 * are NOT checked against an official country list — only against the shape.
 * settlementPatterns: null, or a list of unique, trimmed, non-empty strings;
 * an empty list means "no narrowing" and normalizes to null.
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
    private ?array $settlementPatterns;

    /**
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementPatterns
     */
    private function __construct(
        private ?string $id,
        string $name,
        int $sortOrder,
        array $countryCodes,
        ?array $settlementPatterns,
    ) {
        $this->apply($name, $sortOrder, $countryCodes, $settlementPatterns);
    }

    /**
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementPatterns
     */
    public static function create(string $name, int $sortOrder, array $countryCodes, ?array $settlementPatterns = null): self
    {
        return new self(null, $name, $sortOrder, $countryCodes, $settlementPatterns);
    }

    /**
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the data
     * is already-valid data read back from storage.
     *
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementPatterns
     */
    public static function reconstituteFromStorage(string $id, string $name, int $sortOrder, array $countryCodes, ?array $settlementPatterns): self
    {
        return new self($id, $name, $sortOrder, $countryCodes, $settlementPatterns);
    }

    /**
     * Rewrites every editable field, re-running the construction-time
     * validation — never a looser rule set for updates (same shape as
     * Address::update()).
     *
     * @param  list<string>  $countryCodes
     * @param  list<string>|null  $settlementPatterns
     */
    public function update(string $name, int $sortOrder, array $countryCodes, ?array $settlementPatterns = null): void
    {
        $this->apply($name, $sortOrder, $countryCodes, $settlementPatterns);
    }

    /**
     * Validates everything first and assigns last, so a rejected update leaves
     * the entity exactly as it was.
     *
     * @param  array<mixed>  $countryCodes
     * @param  array<mixed>|null  $settlementPatterns
     */
    private function apply(string $name, int $sortOrder, array $countryCodes, ?array $settlementPatterns): void
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
        $settlementPatterns = self::normalizeSettlementPatterns($settlementPatterns);

        $this->name = $name;
        $this->sortOrder = $sortOrder;
        $this->countryCodes = $countryCodes;
        $this->settlementPatterns = $settlementPatterns;
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
     * @param  array<mixed>|null  $patterns
     * @return list<string>|null
     */
    private static function normalizeSettlementPatterns(?array $patterns): ?array
    {
        if ($patterns === null || $patterns === []) {
            return null;
        }

        $seen = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                throw InvalidShippingZoneException::invalidSettlementPattern($pattern);
            }

            $pattern = trim($pattern);

            if (isset($seen[$pattern])) {
                throw InvalidShippingZoneException::duplicateSettlementPattern($pattern);
            }

            $seen[$pattern] = true;
        }

        // array_keys() would turn a numeric-looking pattern such as "1000" (a
        // postcode) into an int key, so the keys are cast back to strings.
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

    /** @return list<string>|null */
    public function settlementPatterns(): ?array
    {
        return $this->settlementPatterns;
    }
}
