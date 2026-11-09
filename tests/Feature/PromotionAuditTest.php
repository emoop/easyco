<?php

namespace Tests\Feature;

use App\Filament\Pages\ActivityLogJournal;
use App\Filament\Resources\PromotionResource;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Services\PromotionWriter;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * P5c: the promotion writer's activity-log entries (created, one entry per changed field, the scopes as one compact list, the status, nothing for a no-op, inside the save's transaction, honouring
 * `admin.activity_log_enabled` through ActivityLogger), the journal's readable entity name, the read-only «restricted to N customers» line, the warning that counts an ACCOUNT scope, and the money format of the list.
 */
class PromotionAuditTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function state(array $over = []): array
    {
        return $over + ['code' => 'AUD'.(++self::$n), 'discount_type' => 'percentage', 'percentage' => '10', 'is_active' => true, 'scopes' => []];
    }

    private function make(array $over = []): PromotionModel
    {
        $promotion = app(PromotionWriter::class)->create($this->state($over));

        return PromotionModel::query()->findOrFail($promotion->id());
    }

    /** @return list<array<string, mixed>> the promotion entries of the log, oldest first */
    private function entries(?PromotionModel $row = null): array
    {
        return DB::table('activity_log')->where('entity_type', 'promotion')
            ->when($row !== null, fn ($q) => $q->where('entity_id', (string) $row->id))
            ->orderBy('id')->get(['action', 'field', 'old_value', 'new_value', 'entity_id'])->map(fn ($r) => (array) $r)->all();
    }

    private function brand(string $name): string
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name).(++self::$n));
        app(BrandRepository::class)->save($brand);

        return (string) $brand->id();
    }

    // ---- the entries -----------------------------------------------------------------------------------------------

    /** ★ create writes exactly one `created` entry */
    public function test_create_writes_exactly_one_created_entry(): void
    {
        $row = $this->make();

        $this->assertSame([['action' => 'created', 'field' => null, 'old_value' => null, 'new_value' => null, 'entity_id' => (string) $row->id]], $this->entries());
    }

    /** ★ an update that changes only the minimum writes exactly one `updated` entry with the right old and new value */
    public function test_an_update_of_one_field_writes_one_entry_with_old_and_new(): void
    {
        $row = $this->make();
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '79,99', 'is_active' => true]));

        $this->assertSame([['action' => 'updated', 'field' => 'minimum_spend', 'old_value' => '', 'new_value' => '79.99', 'entity_id' => (string) $row->id]], $this->entries());

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '100', 'is_active' => true]));
        $this->assertSame(['79.99', '100.00'], [$this->entries()[1]['old_value'], $this->entries()[1]['new_value']]);
    }

    public function test_one_entry_per_changed_field_with_readable_values(): void
    {
        $row = $this->make();
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->update((string) $row->id, $this->state([
            'is_public' => true, 'public_description' => 'Ten off', 'valid_until' => '2031-01-01 10:00:00', 'usage_limit_total' => '5', 'new_customers_only' => true, 'is_active' => true,
        ]));

        $byField = [];
        foreach ($this->entries() as $entry) {
            $byField[$entry['field']] = [$entry['old_value'], $entry['new_value']];
        }

        $this->assertEqualsCanonicalizing(['is_public', 'public_description', 'valid_until', 'usage_limit_total', 'new_customers_only'], array_keys($byField));
        $this->assertSame(['0', '1'], $byField['is_public']);
        $this->assertSame(['', 'Ten off'], $byField['public_description']);
        $this->assertSame(['', '2031-01-01 10:00'], $byField['valid_until']);
        $this->assertSame(['', '5'], $byField['usage_limit_total']);
        $this->assertSame(['0', '1'], $byField['new_customers_only']);
        $this->assertCount(5, $this->entries(), 'one entry per field, nothing else');
    }

    /** ★ an update that changes nothing writes no entry and does not save */
    public function test_an_update_that_changes_nothing_writes_no_entry_and_does_not_save(): void
    {
        $row = $this->make(['minimum_spend' => '10']);
        DB::table('activity_log')->delete();

        $writes = 0;
        $statements = 0;
        DB::listen(function ($query) use (&$writes, &$statements): void {
            $statements++;
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes++;
            }
        });

        // a counting decorator: the repository's save() must not even be CALLED (an unchanged row would not be written by Eloquent either, so the statement count alone cannot tell)
        $real = app(\EasyCo\Promotions\Contracts\PromotionRepository::class);
        $saves = new \ArrayObject();
        app()->instance(\EasyCo\Promotions\Contracts\PromotionRepository::class, new class($real, $saves) implements \EasyCo\Promotions\Contracts\PromotionRepository {
            public function __construct(private readonly \EasyCo\Promotions\Contracts\PromotionRepository $real, private readonly \ArrayObject $saves)
            {
            }

            public function save(\EasyCo\Promotions\Promotion $promotion): void
            {
                $this->saves[] = 'save';
                $this->real->save($promotion);
            }

            public function findById(string $id): ?\EasyCo\Promotions\Promotion
            {
                return $this->real->findById($id);
            }

            public function findByCode(string $code): ?\EasyCo\Promotions\Promotion
            {
                return $this->real->findByCode($code);
            }

            public function all(): array
            {
                return $this->real->all();
            }

            public function allPublicActive(): array
            {
                return $this->real->allPublicActive();
            }
        });

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '10.00', 'is_active' => true]));

        $this->assertCount(0, $saves, 'save() was not called');
        $this->assertSame(0, $writes);

        $this->assertSame([], $this->entries());
        $this->assertSame(0, $writes, 'no write statement at all');
    }

    /** ★ switching off writes one `status` entry; switching to the state it already has writes nothing */
    public function test_switching_off_and_on_writes_one_status_entry_each(): void
    {
        $row = $this->make();
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->setActive((string) $row->id, true);
        $this->assertSame([], $this->entries(), 'already active: nothing');

        app(PromotionWriter::class)->setActive((string) $row->id, false);
        app(PromotionWriter::class)->setActive((string) $row->id, true);

        $this->assertSame([['status', 'active', 'inactive'], ['status', 'inactive', 'active']], array_map(fn ($e) => [$e['field'], $e['old_value'], $e['new_value']], $this->entries()));
    }

    public function test_the_status_inside_an_update_is_one_status_entry(): void
    {
        $row = $this->make();
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['is_active' => false]));

        $this->assertSame([['status', 'active', 'inactive']], array_map(fn ($e) => [$e['field'], $e['old_value'], $e['new_value']], $this->entries()));
    }

    /** ★ a change of the scopes is ONE entry with a compact sorted list */
    public function test_a_scopes_change_is_one_entry_with_the_sorted_compact_list(): void
    {
        $a = $this->brand('Alpha');
        $b = $this->brand('Beta');
        $tag = (string) DB::table('catalog_tags')->insertGetId(['name' => 'T', 'slug' => 't-'.(++self::$n), 'created_at' => now(), 'updated_at' => now()]);
        $row = $this->make(['scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $a]]]);
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['is_active' => true, 'scopes' => [
            ['type' => 'tag', 'mode' => 'exclude', 'reference' => $tag],
            ['type' => 'brand', 'mode' => 'include', 'reference' => $b],
            ['type' => 'brand', 'mode' => 'include', 'reference' => $a],
        ]]));

        $expected = collect(['brand:include:'.$a, 'brand:include:'.$b, 'tag:exclude:'.$tag])->sort()->implode(', ');
        $entries = $this->entries();

        $this->assertCount(1, $entries);
        $this->assertSame(['scopes', 'brand:include:'.$a, $expected], [$entries[0]['field'], $entries[0]['old_value'], $entries[0]['new_value']]);
    }

    public function test_an_account_scope_never_appears_in_an_entry(): void
    {
        $row = $this->make();
        app(PromotionScopeRepository::class)->attach(new PromotionScope(null, (string) $row->id, PromotionScopeType::ACCOUNT, 'private-customer-1', PromotionScopeMode::INCLUDE));
        DB::table('activity_log')->delete();

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '5', 'is_active' => true]));

        $this->assertStringNotContainsString('private-customer-1', json_encode(DB::table('activity_log')->get()));
        $this->assertSame(['minimum_spend'], array_column($this->entries(), 'field'));
    }

    /** with the activity log off nothing is written (ActivityLogger's own gate, the same as the shipping writer) */
    public function test_nothing_is_written_while_the_activity_log_is_off(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '0');

        $row = $this->make();
        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '9', 'is_active' => true]));
        app(PromotionWriter::class)->setActive((string) $row->id, false);

        $this->assertSame([], $this->entries());
    }

    /** ★ the entry is written inside the save's transaction: when the save fails no entry remains */
    public function test_a_failed_save_leaves_no_entry(): void
    {
        $row = $this->make();
        $brand = $this->brand('Gamma');
        DB::table('activity_log')->delete();

        $real = app(PromotionScopeRepository::class);
        app()->instance(PromotionScopeRepository::class, new class($real) implements PromotionScopeRepository {
            public function __construct(private readonly PromotionScopeRepository $real)
            {
            }

            public function attach(PromotionScope $scope): void
            {
                throw new RuntimeException('the save fails after the promotion row was written');
            }

            public function detach(string $scopeId): void
            {
                $this->real->detach($scopeId);
            }

            public function findByPromotionId(string $promotionId): array
            {
                return $this->real->findByPromotionId($promotionId);
            }
        });

        try {
            app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '50', 'is_active' => true, 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]]]));
            $this->fail('expected the failure');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->entries(), 'no entry remains');
        $this->assertNull(PromotionModel::query()->find($row->id)->minimum_spend_amount_minor, 'and the promotion row was rolled back too');

        // creating fails the same way: no promotion, no entry
        try {
            app(PromotionWriter::class)->create($this->state(['code' => 'rolledback', 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]]]));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->entries());
        $this->assertNull(PromotionModel::query()->where('code', 'rolledback')->first());
    }

    /** ★ the entry is written in the SAME transaction as the save, not after it */
    public function test_the_entry_and_the_save_share_one_transaction(): void
    {
        $row = $this->make();
        DB::table('activity_log')->delete();

        $base = DB::transactionLevel();
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (preg_match('/^\s*(insert into|update)\s+`?(activity_log|promotions)`?/i', $query->sql, $m) === 1) {
                $levels[strtolower($m[2])] = DB::transactionLevel();
            }
        });

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '33', 'is_active' => true]));

        $this->assertSame($base + 1, $levels['promotions'] ?? null, 'the save is inside the writer transaction');
        $this->assertSame($base + 1, $levels['activity_log'] ?? null, 'and so is the entry');
    }

    // ---- the journal -----------------------------------------------------------------------------------------------

    /** ★ the journal shows the promotion entry under its readable entity name, in both languages */
    public function test_the_journal_shows_the_readable_entity_name(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'journalcode']);

        app()->setLocale('bg');
        Livewire::test(ActivityLogJournal::class)->assertSee('Промокод')->assertSee((string) $row->id);

        app()->setLocale('en');
        Livewire::test(ActivityLogJournal::class)->assertSee('Promo code');

        $this->assertSame('Промокод', trans('journal.entities.promotion', [], 'bg'));
        $this->assertSame('Promo code', trans('journal.entities.promotion', [], 'en'));
    }

    // ---- the read-only customer restriction ------------------------------------------------------------------------

    private function withAccountScopes(PromotionModel $row, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            app(PromotionScopeRepository::class)->attach(new PromotionScope(null, (string) $row->id, PromotionScopeType::ACCOUNT, 'acct-'.$i, $i === 2 ? PromotionScopeMode::EXCLUDE : PromotionScopeMode::INCLUDE));
        }
    }

    /** ★ the line shows «N клиента» for a promotion with N ACCOUNT scopes, and nothing for 0 */
    public function test_the_read_only_line_shows_the_number_of_account_scopes(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $none = $this->make();
        $two = $this->make();
        $one = $this->make();
        $this->withAccountScopes($two, 2);
        $this->withAccountScopes($one, 1);

        $this->assertStringNotContainsString('data-promotion-account-scopes', Livewire::test(EditPromotion::class, ['record' => $none->id])->html());

        $html = Livewire::test(EditPromotion::class, ['record' => $two->id])->html();
        $this->assertStringContainsString('data-promotion-account-scopes', $html);
        $this->assertStringContainsString('Ограничен до 2 клиента (задава се само през API)', $html);
        $this->assertStringContainsString('Ограничен до 1 клиент (задава се само през API)', Livewire::test(EditPromotion::class, ['record' => $one->id])->html());

        app()->setLocale('en');
        $this->assertStringContainsString('Restricted to 2 customers (set through the API only)', Livewire::test(EditPromotion::class, ['record' => $two->id])->html());
    }

    /** ★ saving keeps the ACCOUNT scopes unchanged, and the line's value is not submitted */
    public function test_saving_keeps_the_account_scopes_and_does_not_submit_the_count(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make();
        $this->withAccountScopes($row, 2);
        $before = PromotionScopeModel::query()->where('promotion_id', $row->id)->orderBy('id')->get(['id', 'scope_type', 'mode', 'scope_reference_id'])->toArray();

        $page = Livewire::test(EditPromotion::class, ['record' => $row->id])->fillForm(['minimum_spend' => '12'])->call('save')->assertHasNoFormErrors();

        $this->assertSame($before, PromotionScopeModel::query()->where('promotion_id', $row->id)->orderBy('id')->get(['id', 'scope_type', 'mode', 'scope_reference_id'])->toArray());
        $this->assertArrayNotHasKey('account_scope_count', $page->instance()->form->getState());
    }

    /** ★ the amber warning does NOT appear for a public code whose only restriction is an ACCOUNT scope, and still appears for a public code with none */
    public function test_an_account_scope_counts_as_a_condition_for_the_warning(): void
    {
        $this->actingAsAdministrator();
        $restricted = $this->make(['is_public' => true, 'public_description' => 'Only for some']);
        $this->withAccountScopes($restricted, 1);
        $open = $this->make(['is_public' => true, 'public_description' => 'For everybody']);

        $this->assertStringNotContainsString('data-promotion-public-warning', Livewire::test(EditPromotion::class, ['record' => $restricted->id])->html());
        $this->assertStringContainsString('data-promotion-public-warning', Livewire::test(EditPromotion::class, ['record' => $open->id])->html());

        $this->assertTrue(PromotionResource::hasAnyCondition(null, [], false, null, [], accountScopes: 1));
        $this->assertFalse(PromotionResource::hasAnyCondition(null, [], false, null, [], accountScopes: 0));
    }

    // ---- the money format of the list ------------------------------------------------------------------------------

    /** ★ every money value of the list (the discount, the minimum, the maximum) goes through the shop's own PriceDisplayFormatter and follows its setting */
    public function test_the_list_shows_money_in_the_shops_own_format(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $this->make(['code' => 'fixedfifty', 'discount_type' => 'fixed_amount', 'discount_amount' => '50', 'minimum_spend' => '79.99', 'maximum_spend' => '500']);

        // the shop's default: the symbol after the amount, with a space
        Livewire::test(ListPromotions::class)->assertSee('50.00 €')->assertSee('мин. 79.99 €')->assertSee('макс. 500.00 €');

        // the merchant's own setting is followed (the same one the cart and the orders use)
        app(SiteSettingsRepository::class)->set('site.currency_symbol_position', 'prefix');
        Livewire::test(ListPromotions::class)->assertSee('€50.00')->assertSee('мин. €79.99')->assertSee('макс. €500.00');

        // percentages stay as they are
        $this->make(['code' => 'pctone', 'percentage' => '12.5']);
        Livewire::test(ListPromotions::class)->assertSee('12,5 %');
    }
}
