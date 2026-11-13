<?php

namespace EasyCo\Catalog;

/**
 * One typed number of the grid: (size value, measure) -> hundredths. A value object: no id, no behaviour but the range rule (MeasurementNumber). Repositories take and return plain arrays for the
 * whole grid; this is the single-cell form for callers that want one.
 */
final class MeasurementValue
{
    public readonly int $hundredths;

    public function __construct(
        public readonly string $sizeValueId,
        public readonly string $measureId,
        int $hundredths,
    ) {
        $this->hundredths = MeasurementNumber::assertInRange($hundredths);
    }

    public static function fromInput(string $sizeValueId, string $measureId, string|int|float|null $raw): ?self
    {
        $hundredths = MeasurementNumber::parse($raw);

        return $hundredths === null ? null : new self($sizeValueId, $measureId, $hundredths);
    }

    public function formatted(string $locale = 'bg'): string
    {
        return MeasurementNumber::format($this->hundredths, $locale);
    }
}
