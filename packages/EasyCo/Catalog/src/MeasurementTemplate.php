<?php

namespace EasyCo\Catalog;

use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use LogicException;

/**
 * A named, ORDERED list of measures ("Блузи": гърди, талия, дължина). A product picks one. The list holds measure ids only (a plain string contract, no entity graph); each measure at most once,
 * at most 20. Whether an id names a real measure is the repository's check (a foreign key is the last word).
 */
final class MeasurementTemplate
{
    public const NAME_MAX_LENGTH = 120;

    public const MAX_MEASURES = 20;

    private string $name;

    /** @var string[] */
    private array $measureIds;

    /**
     * @param  string[]  $measureIds  in display order
     */
    public function __construct(
        private ?string $id,
        string $name,
        array $measureIds = [],
        private int $sort = 0,
        private bool $active = true,
        private MeasurementTemplateKind $kind = MeasurementTemplateKind::BY_SIZE,
    ) {
        $this->name = self::cleanName($name);
        $this->measureIds = self::cleanMeasureIds($measureIds);

        if ($sort < 0) {
            throw InvalidMeasurementValueException::invalidMeasureList('a sort position cannot be negative');
        }
    }

    /** @param string[] $measureIds */
    public static function create(string $name, array $measureIds = [], int $sort = 0, MeasurementTemplateKind $kind = MeasurementTemplateKind::BY_SIZE): self
    {
        return new self(null, $name, $measureIds, $sort, true, $kind);
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length < 1 || $length > self::NAME_MAX_LENGTH) {
            throw InvalidMeasurementValueException::invalidName('template');
        }

        return $name;
    }

    /**
     * @param  string[]  $ids
     * @return string[]
     */
    private static function cleanMeasureIds(array $ids): array
    {
        $ids = array_values(array_map('strval', $ids));

        if (count($ids) > self::MAX_MEASURES) {
            throw InvalidMeasurementValueException::invalidMeasureList('at most '.self::MAX_MEASURES.' measures');
        }

        foreach ($ids as $id) {
            if ($id === '') {
                throw InvalidMeasurementValueException::invalidMeasureList('an empty measure id');
            }
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw InvalidMeasurementValueException::invalidMeasureList('a measure can be in a template only once');
        }

        return $ids;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('MeasurementTemplate already has an id; assignId() is a one-time operation.');
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

    /** @return string[] */
    public function measureIds(): array
    {
        return $this->measureIds;
    }

    /** @param string[] $measureIds the new complete ordered list */
    public function setMeasureIds(array $measureIds): void
    {
        $this->measureIds = self::cleanMeasureIds($measureIds);
    }

    public function sort(): int
    {
        return $this->sort;
    }

    public function changeSort(int $sort): void
    {
        if ($sort < 0) {
            throw InvalidMeasurementValueException::invalidMeasureList('a sort position cannot be negative');
        }

        $this->sort = $sort;
    }

    public function kind(): MeasurementTemplateKind
    {
        return $this->kind;
    }

    /** Whether the kind may change once products hold numbers is the repository's rule (`kind_locked`); this class cannot see the numbers. */
    public function changeKind(MeasurementTemplateKind $kind): void
    {
        $this->kind = $kind;
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
