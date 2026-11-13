<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\MeasureUnit;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use LogicException;

/**
 * One thing a merchant measures ("Гръдна обиколка", "Дължина"), with ONE unit from the closed list (MeasureUnit). A standalone lookup entity of the Season's shape. Archiving (`active = false`)
 * hides it from the pickers but keeps every stored number; deleting is only for a measure that nothing uses (the repository decides). Whether the unit may still change depends on stored
 * numbers, which this class cannot see: EloquentMeasureRepository::save() refuses it.
 */
final class Measure
{
    public const NAME_MAX_LENGTH = 120;

    private string $name;

    public function __construct(
        private ?string $id,
        string $name,
        private MeasureUnit $unit,
        private int $sort = 0,
        private bool $active = true,
    ) {
        $this->name = self::cleanName($name);
        self::assertSort($sort);
    }

    public static function create(string $name, string $unit, int $sort = 0): self
    {
        return new self(null, $name, self::unitFrom($unit), $sort, true);
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::NAME_MAX_LENGTH) {
            throw InvalidMeasurementValueException::invalidName('measure');
        }

        return $name;
    }

    private static function assertSort(int $sort): void
    {
        if ($sort < 0) {
            throw InvalidMeasurementValueException::invalidMeasureList('a sort position cannot be negative');
        }
    }

    public static function unitFrom(string $unit): MeasureUnit
    {
        return MeasureUnit::tryFrom($unit) ?? throw InvalidMeasurementValueException::unknownUnit($unit);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Measure already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $newName): void
    {
        $this->name = self::cleanName($newName);
    }

    public function unit(): MeasureUnit
    {
        return $this->unit;
    }

    public function changeUnit(string $unit): void
    {
        $this->unit = self::unitFrom($unit);
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function changeSort(int $sort): void
    {
        self::assertSort($sort);
        $this->sort = $sort;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function archive(): void
    {
        $this->active = false;
    }

    public function unarchive(): void
    {
        $this->active = true;
    }
}
