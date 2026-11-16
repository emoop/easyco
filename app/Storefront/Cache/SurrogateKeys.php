<?php

namespace App\Storefront\Cache;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Log;

/**
 * P8b: the surrogate keys (`xkey`) of the page being built, request-scoped. Controllers and the reader only ADD keys (pure PHP over data already
 * loaded: no query); StorefrontCacheHeaders writes them into the response when the page is cacheable. A key that does not match the closed
 * pattern is ignored and logged once.
 */
#[Scoped]
final class SurrogateKeys
{
    public const PATTERN = '/^(all|home|sitemap|idx-brand|idx-collection|p\d+|c\d+|b\d+|s\d+|t\d+|col\d+|pg\d+)$/';

    /** @var array<string, true> */
    private array $keys = [];

    private bool $warned = false;

    public function add(string ...$keys): self
    {
        foreach ($keys as $key) {
            if (preg_match(self::PATTERN, $key) === 1) {
                $this->keys[$key] = true;

                continue;
            }

            if (! $this->warned) {
                $this->warned = true;
                Log::warning('Surrogate key ignored: not in the closed key list.', ['key' => mb_substr($key, 0, 40)]);
            }
        }

        return $this;
    }

    public function product(int|string $id): self
    {
        return $this->add('p'.$id);
    }

    public function category(int|string $id): self
    {
        return $this->add('c'.$id);
    }

    public function brand(int|string $id): self
    {
        return $this->add('b'.$id);
    }

    public function season(int|string $id): self
    {
        return $this->add('s'.$id);
    }

    public function tag(int|string $id): self
    {
        return $this->add('t'.$id);
    }

    public function collection(int|string $id): self
    {
        return $this->add('col'.$id);
    }

    public function page(int|string $id): self
    {
        return $this->add('pg'.$id);
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->keys);
    }
}
