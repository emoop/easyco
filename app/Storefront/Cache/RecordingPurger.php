<?php

namespace App\Storefront\Cache;

/** P8b: for tests: records every batch it was given. */
final class RecordingPurger implements CachePurger
{
    /** @var list<list<string>> */
    public array $batches = [];

    public bool $fail = false;

    public function purge(array $keys): void
    {
        if ($this->fail) {
            throw new \RuntimeException('purge failed');
        }

        $this->batches[] = array_values($keys);
    }

    public function isActive(): bool
    {
        return true;
    }

    /** @return list<string> every key of every batch, sorted and unique */
    public function keys(): array
    {
        $all = array_unique(array_merge([], ...$this->batches));
        sort($all);

        return array_values($all);
    }
}
