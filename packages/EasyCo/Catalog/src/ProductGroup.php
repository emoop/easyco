<?php

namespace EasyCo\Catalog;

use InvalidArgumentException;
use LogicException;

/**
 * A merchant-defined internal merchandise/reporting group (e.g.
 * "Обувки", "Комплекти") — catalog-domain-design.md §3.15. Explicitly
 * distinct from a future, legally-mandated VAT tax group (Наредба
 * Н-18), out of scope until v2. Not tied to Category — a dress and a
 * dress+blouse set can share a Category but belong to different
 * ProductGroups.
 *
 * `code` + `name` shape, mirroring AttributeDefinition's own
 * constructor fields — but with real validation on both (unlike
 * AttributeDefinition, whose constructor validates neither; every
 * other simple lookup entity in this package — Brand/Category/Tag/
 * Season — does validate its name, and code_unique is enforced at the
 * DB level the same way AttributeDefinition::code's own unique index
 * is, so the same "must not be empty" check applies here for
 * consistency with the majority precedent).
 *
 * `code` has no mutator — same reasoning as
 * AttributeDefinition::code's own immutability (§3.12): a stable
 * machine identifier other things (reporting, imports) key against.
 * Only `name` (the display label) can change after creation.
 */
final class ProductGroup
{
    public function __construct(
        private ?string $id,
        private readonly string $code,
        private string $name,
    ) {
        self::assertValidCode($code);
        self::assertValidName($name);
    }

    private static function assertValidCode(string $code): void
    {
        if ($code === '') {
            throw new InvalidArgumentException('ProductGroup code must not be empty.');
        }
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('ProductGroup name must not be empty.');
        }
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('ProductGroup already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $newName): void
    {
        self::assertValidName($newName);
        $this->name = $newName;
    }
}
