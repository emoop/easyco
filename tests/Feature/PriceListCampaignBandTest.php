<?php

namespace Tests\Feature;

use App\Jobs\RefreshProductSortPrices;
use App\Services\CatalogScopeResolver;
use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceListWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceRangeResolver;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\PriceListSignature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6b.1: ANY number of campaigns may share a scope. The WRITER picks the priority inside the campaign band 1…999: the lowest number ≥ 500 free for the campaign's FINAL scope signature (500, 501, 502…), else the highest
 * free number below 500, else the scope error (band full). A scope edit that lands on a taken (priority, signature) moves the campaign to a free priority; a lost race on the unique index is retried ONCE.
 */
class PriceListCampaignBandTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private static int $n = 0;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->makeTag(1, 'A', 'a');
        $this->makeTag(2, 'B', 'b');
        $this->makeProduct(1, ['slug' => 'one', 'price' => '100.00', 'stock' => 1, 'tags' => [1]]);
        $this->makeProduct(2, ['slug' => 'two', 'price' => '100.00', 'stock' => 1, 'tags' => [2]]);
    }

    /** @param array<string, mixed> $over */
    private function data(array $over = []): array
    {
        return $over + ['name' => 'Campaign '.(++self::$n), 'percentage' => '10', 'is_active' => true, 'replaces_manual_sale' => false, 'confirm_all_products' => true, 'scopes' => [['type' => 'tag', 'reference' => '1']]];
    }

    private function make(array $over = []): PriceList
    {
        return app(PriceListWriter::class)->create($this->data($over));
    }

    private function priority(PriceList|string $list): int
    {
        return (int) DB::table('pricing_price_lists')->where('id', $list instanceof PriceList ? $list->id() : $list)->value('priority');
    }

    private function finalMinor(int $variationId): ?int
    {
        $data = app(CatalogScopeResolver::class)->forVariations([(string) $variationId])[(string) $variationId];
        $quotes = app(PriceRangeResolver::class)->resolveQuotes([new PriceContext(priceableId: (string) $variationId, quantity: 1, currency: 'EUR', productId: $data['productId'], matchingScopeReferenceIds: $data['matchingScopeReferenceIds'])]);

        return isset($quotes[(string) $variationId]) ? $quotes[(string) $variationId]->final->gross()->minorValue() : null;
    }

    private function tagSignature(string $tag): string
    {
        return PriceListSignature::forScopes([new PriceListScope(null, '', PriceListScopeType::TAG, $tag)])->value();
    }

    /** Fills the band with raw rows of one signature (inactive campaigns). */
    private function fillBand(string $signature, int $from, int $to): void
    {
        $rows = [];

        for ($priority = $from; $priority <= $to; $priority++) {
            $rows[] = ['name' => 'filler '.$priority, 'mode' => 'percentage_off_regular', 'percentage_basis_points' => 100, 'priority' => $priority, 'status' => 'inactive', 'is_system' => 0, 'scope_signature' => $signature, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('pricing_price_lists')->insert($chunk);
        }
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return DB::table('activity_log')->where('entity_type', 'price_list')->orderBy('id')->get(['action', 'field'])->map(fn ($r) => (array) $r)->all();
    }

    private function record(string $hook): void
    {
        Hook::action($hook, function (...$args) use ($hook): void {
            $this->calls[] = [$hook, $args];
        });
    }

    // ---- the priority rule -----------------------------------------------------------------------------------------

    /** ★ three campaigns on one scope get 500, 501, 502 — the same for «all products» (its own signature); the newest wins, switching it off returns the previous one */
    public function test_campaigns_on_one_scope_get_500_501_502_and_the_newest_wins(): void
    {
        $a = $this->make(['percentage' => '10']);
        $b = $this->make(['percentage' => '20']);
        $c = $this->make(['percentage' => '30']);

        $this->assertSame([500, 501, 502], [$this->priority($a), $this->priority($b), $this->priority($c)]);
        $this->assertSame(7000, $this->finalMinor(101), 'the newest campaign (−30%) wins on a product that matches all of them');

        app(PriceListWriter::class)->setActive((string) $c->id(), false);
        $this->assertSame(8000, $this->finalMinor(101), 'switched off: the previous one (−20%) applies');

        $x = $this->make(['scopes' => [], 'percentage' => '5']);
        $y = $this->make(['scopes' => [], 'percentage' => '6']);
        $z = $this->make(['scopes' => [], 'percentage' => '7']);
        $this->assertSame([500, 501, 502], [$this->priority($x), $this->priority($y), $this->priority($z)], '«all products» has its own band');
        $this->assertSame(9300, $this->finalMinor(101), 'tag A has 500 and 501 (c is off); «all products» has 500, 501, 502: the universal campaign at 502 is the highest');
    }

    /** the documented imprecision: a later campaign on a scope can outrank a NEWER campaign on a different, overlapping scope that sits at 500 */
    public function test_a_later_campaign_on_the_same_scope_can_outrank_a_newer_overlapping_one(): void
    {
        $this->make(['percentage' => '10']);                                    // tag A, 500
        $this->make(['percentage' => '20']);                                    // tag A, 501
        $newer = $this->make(['scopes' => [], 'percentage' => '30']);           // all products, 500 (the NEWEST list)

        $this->assertSame(500, $this->priority($newer));
        $this->assertSame(8000, $this->finalMinor(101), 'tag-A campaign at 501 outranks the newer «all products» campaign at 500');
    }

    /** ★ the highest free number below 500 is used when 500…999 is full; the whole band full is the scope error, nothing half-written */
    public function test_a_full_upper_band_uses_below_500_and_a_full_band_is_the_scope_error(): void
    {
        $signature = $this->tagSignature('1');
        $this->fillBand($signature, 500, 999);

        $low = $this->make();
        $this->assertSame(499, $this->priority($low), 'the highest free number below 500');

        $this->fillBand($signature, 1, 498);
        Queue::fake();
        $this->record('pricing.price_list.created');
        $lists = PriceListModel::query()->count();
        $entries = count($this->entries());

        try {
            $this->make();
            $this->fail('expected the scope error');
        } catch (PriceListInvalidException $exception) {
            $this->assertArrayHasKey('scopes', $exception->errors);
            $this->assertSame(__('price_lists.errors.scope_band_full'), $exception->errors['scopes'][0]);
        }

        $this->assertSame($lists, PriceListModel::query()->count(), 'nothing written');
        $this->assertSame($entries, count($this->entries()), 'no audit entry');
        $this->assertSame([], $this->calls, 'no hook');
        Queue::assertNothingPushed();
    }

    // ---- update ------------------------------------------------------------------------------------------------------

    /** ★ a scope edited into a scope another campaign already has moves to a free priority and keeps working; an unchanged scope keeps its priority */
    public function test_a_scope_edit_onto_a_taken_pair_moves_the_campaign(): void
    {
        $a = $this->make(['percentage' => '10']);                                              // tag A, 500
        $b = $this->make(['percentage' => '30', 'scopes' => [['type' => 'tag', 'reference' => '2']]]); // tag B, 500

        $this->assertSame([500, 500], [$this->priority($a), $this->priority($b)]);
        $this->assertSame(7000, $this->finalMinor(201), 'B applies to the product of tag B');
        $this->assertSame(9000, $this->finalMinor(101));

        // a rename keeps the priority
        app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => 'Renamed', 'percentage' => '30', 'scopes' => [['type' => 'tag', 'reference' => '2']]]));
        $this->assertSame(500, $this->priority($b), 'the scope did not change: the priority did not change');

        // B edited onto tag A: (500, tag A) is taken by A → B goes to 501
        app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => 'Renamed', 'percentage' => '30', 'scopes' => [['type' => 'tag', 'reference' => '1']]]));
        $this->assertSame([500, 501], [$this->priority($a), $this->priority($b)]);
        $this->assertSame(7000, $this->finalMinor(101), 'B (501) now outranks A (500) on the product of tag A');
        $this->assertSame(10000, $this->finalMinor(201), 'tag B is not covered any more');
        $this->assertSame(501, (new \ReflectionProperty(PriceList::class, 'priority'))->getValue(app(PriceListRepository::class)->findById((string) $b->id())), 'the stored entity agrees');

        // an edit onto a scope nobody has keeps the priority
        app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => 'Renamed', 'percentage' => '30', 'scopes' => [['type' => 'tag', 'reference' => '2']]]));
        $this->assertSame(501, $this->priority($b), 'a free pair: the priority stays where it was');
    }

    /** a scope edit into a scope with a full band: the scope error, the campaign stays as it was */
    public function test_a_scope_edit_into_a_full_band_is_the_scope_error(): void
    {
        $b = $this->make(['scopes' => [['type' => 'tag', 'reference' => '2']]]);
        $this->fillBand($this->tagSignature('1'), 1, 999);
        DB::table('activity_log')->delete();
        Queue::fake();

        try {
            app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => $b->name(), 'scopes' => [['type' => 'tag', 'reference' => '1']]]));
            $this->fail('expected the scope error');
        } catch (PriceListInvalidException $exception) {
            $this->assertArrayHasKey('scopes', $exception->errors);
        }

        $this->assertSame([['tag', '2']], DB::table('pricing_price_list_scopes')->where('price_list_id', $b->id())->get(['scope_type', 'scope_reference_id'])->map(fn ($r) => [$r->scope_type, $r->scope_reference_id])->all());
        $this->assertSame(500, $this->priority($b));
        $this->assertSame([], $this->entries());
        Queue::assertNothingPushed();
    }

    // ---- the race ----------------------------------------------------------------------------------------------------

    /** A repository that inserts the list and then fails like a lost race on the unique index, N times. */
    private function racing(int $times): void
    {
        $real = app(PriceListRepository::class);
        $this->app->instance(PriceListRepository::class, new class($real, $times) implements PriceListRepository {
            public function __construct(private readonly PriceListRepository $real, private int $times)
            {
            }

            public function save(PriceList $priceList): void
            {
                $this->real->save($priceList);
            }

            public function saveWithScopes(PriceList $priceList, array $scopes): void
            {
                $this->real->saveWithScopes($priceList, $scopes);

                if ($this->times-- > 0) {
                    $previous = new PDOException('Duplicate entry');
                    $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];

                    throw new QueryException('testing', 'insert into pricing_price_lists', [], $previous);
                }
            }

            public function findById(string $id): ?PriceList
            {
                return $this->real->findById($id);
            }

            public function existsActiveAtPriority(int $priority, ?string $excludingId = null): bool
            {
                return $this->real->existsActiveAtPriority($priority, $excludingId);
            }

            public function findAllActiveAndValidAt(\DateTimeImmutable $at): array
            {
                return $this->real->findAllActiveAndValidAt($at);
            }

            public function findSystemListByName(string $name): ?PriceList
            {
                return $this->real->findSystemListByName($name);
            }
        });
    }

    /** ★ a lost race on the unique index is retried ONCE and succeeds: one list, one audit entry, one hook, one refresh */
    public function test_a_lost_race_is_retried_once_and_succeeds(): void
    {
        $this->racing(1);
        Queue::fake();
        $this->record('pricing.price_list.created');

        $list = $this->make();

        $this->assertNotNull($list->id());
        $this->assertSame(1, PriceListModel::query()->where('is_system', false)->count(), 'the first attempt was rolled back');
        $this->assertSame(500, $this->priority($list));
        $this->assertSame([['action' => 'created', 'field' => null]], $this->entries());
        $this->assertCount(1, $this->calls);
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class));
    }

    /** ★ a second failure shows the scope error, nothing remains */
    public function test_a_second_lost_race_is_the_scope_error(): void
    {
        $this->racing(2);
        Queue::fake();
        $this->record('pricing.price_list.created');

        try {
            $this->make();
            $this->fail('expected the scope error');
        } catch (PriceListInvalidException $exception) {
            $this->assertArrayHasKey('scopes', $exception->errors);
        }

        $this->assertSame(0, PriceListModel::query()->where('is_system', false)->count());
        $this->assertSame([], $this->entries());
        $this->assertSame([], $this->calls);
        Queue::assertNothingPushed();
    }

    // ---- P6b behaviour unchanged ---------------------------------------------------------------------------------

    /** ★ with several campaigns on one scope: a no-change save writes nothing, every create queues ONE refresh after the commit */
    public function test_audit_hooks_and_refresh_are_unchanged(): void
    {
        $a = $this->make(['name' => 'Same']);
        Queue::fake();
        $b = $this->make(['name' => 'Same two']);
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class), 'one refresh for the create');

        DB::table('activity_log')->delete();
        Queue::fake();
        $this->record('pricing.price_list.updated');

        app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => 'Same two']));

        $this->assertSame([], $this->entries());
        $this->assertSame([], $this->calls);
        Queue::assertNothingPushed();
        $this->assertSame([500, 501], [$this->priority($a), $this->priority($b)]);
    }

    /** the moved campaign (priority only) writes its normal audit entry for the scope change, and no entry for the priority */
    public function test_a_move_is_audited_as_a_scope_change_only(): void
    {
        $this->make();
        $b = $this->make(['scopes' => [['type' => 'tag', 'reference' => '2']]]);
        DB::table('activity_log')->delete();

        app(PriceListWriter::class)->update((string) $b->id(), $this->data(['name' => $b->name(), 'scopes' => [['type' => 'tag', 'reference' => '1']]]));

        $this->assertSame([['action' => 'updated', 'field' => 'scopes']], $this->entries());
    }
}
