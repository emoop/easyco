<?php

namespace Tests\Feature\Storefront\Cache;

use App\Storefront\Badges\BadgeSet;
use App\Storefront\Badges\BadgeStore;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P8b: several product sections on one page do not repeat the custom-badge statements (a per-request memo, per product id). */
class BadgeMemoTest extends TestCase
{
    use BuildsCacheShop;
    use RefreshDatabase;

    /** the most statements ONE page may add for custom badges that use categories, tags and collections (P7-2: the category pivot, the tags, the collections; the tree is read once per request anyway) */
    private const PINNED_ADDED = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop();
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function home(array $sections): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    /** the custom rows are always there; `$enabled` switches them (a disabled row costs nothing: the baseline of the comparison) */
    private function badges(bool $enabled): void
    {
        $working = BadgeSet::defaults();
        $row = static fn (string $id, array $conditions): array => ['kind' => 'custom', 'id' => $id, 'enabled' => $enabled, 'text' => 'X', 'position' => 'top', 'colour' => 'blue', 'opacity' => 100, 'shape' => 'square', 'priority' => 50, 'conditions' => $conditions];

        array_push($working['badges'], $row('aaaaaa0001', ['categories' => [1]]), $row('aaaaaa0002', ['tags' => [1]]), $row('aaaaaa0003', ['collections' => [1]]));
        [$clean, $errors] = BadgeSet::validate($working);
        $this->assertSame([], $errors, json_encode($errors));
        BadgeStore::publish($clean);
    }

    private function statements(): int
    {
        $this->fresh();
        $this->get('/')->assertOk(); // warm: the appearance cache and the zone reads
        $this->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** ★ two product grids that show the same products, with custom badges of every kind: the page adds a bounded number of statements, not that number per grid */
    public function test_several_sections_do_not_repeat_the_badge_statements(): void
    {
        $grid = static fn (string $source, int $entity): array => ['type' => 'product_grid', 'settings' => ['source' => $source, 'entity' => $entity]];
        // the first grid shows products 1, 2 and 3; the second shows 1 and 2 again: every product of the second grid was already seen
        $this->home([$grid('category', 1), $grid('category', 2)]);

        $this->badges(false);
        $off = $this->statements();
        $this->badges(true);
        $on = $this->statements();

        fwrite(STDERR, "\n[query-count] P8b badge memo, home with 2 product grids: no custom badges {$off}, categories + tags + collections {$on} (+".($on - $off).")\n");

        $this->assertGreaterThan(0, $on - $off, 'the custom badges do read something');
        $this->assertLessThanOrEqual(self::PINNED_ADDED, $on - $off, 'one page, one set of badge statements, whatever the number of sections');
    }

    public function test_the_memo_never_changes_what_is_printed(): void
    {
        $grid = static fn (string $source, int $entity): array => ['type' => 'product_grid', 'settings' => ['source' => $source, 'entity' => $entity]];
        $this->badges(true);
        $count = function (array $sections): int {
            $this->home($sections);
            $this->fresh();

            return substr_count($this->get('/')->assertOk()->getContent(), 'sf-cbadge ');
        };

        $a = $count([$grid('category', 2)]);
        $b = $count([$grid('category', 1)]);
        $both = $count([$grid('category', 2), $grid('category', 1)]);

        $this->assertGreaterThan(0, $a);
        $this->assertSame($a + $b, $both, 'the memo returns the same badges for a product it has already seen');
    }
}
