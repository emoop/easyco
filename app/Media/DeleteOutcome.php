<?php

namespace App\Media;

/** What MediaDeleter::delete() did: `deleted`, `in_use` (with how many places still use the picture) or `not_found`. */
final class DeleteOutcome
{
    public const DELETED = 'deleted';

    public const IN_USE = 'in_use';

    public const NOT_FOUND = 'not_found';

    private function __construct(
        public readonly string $status,
        public readonly int $uses = 0,
    ) {
    }

    public static function deleted(): self
    {
        return new self(self::DELETED);
    }

    public static function inUse(int $uses): self
    {
        return new self(self::IN_USE, $uses);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public function wasDeleted(): bool
    {
        return $this->status === self::DELETED;
    }
}
