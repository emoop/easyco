<?php

namespace App\Storefront\Badges;

use Illuminate\Container\Attributes\Scoped;

/**
 * P8b: the per-PAGE memo of CustomBadgeMatcher: a page with several product sections (home grids, carousels, the slots under a product's gallery) shows the same
 * product more than once, and every section used to repeat the same badge statements. While a page is being rendered (`begin()`, called by the controllers that
 * render several sections) the results are kept per product id and per set of custom rows; only the ids not seen yet are queried. The memo is OFF otherwise, so a
 * direct use of the reader (a job, a test) behaves exactly as before. It ends with the request (the ONE terminating callback of CachePurgeListeners::register()), and a new `begin()` starts empty.
 */
#[Scoped]
final class BadgeMatchMemo
{
    /** @var array<string, array<int, array<string, true>>> rows hash => product id => the matched row ids (an empty array = seen, matches nothing) */
    public array $seen = [];

    public bool $active = false;

    public function begin(): void
    {
        $this->seen = [];
        $this->active = true;
    }

    public function end(): void
    {
        $this->active = false;
        $this->seen = [];
    }
}
