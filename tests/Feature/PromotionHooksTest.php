<?php

namespace Tests\Feature;

use App\Services\Exceptions\PromotionInvalidException;
use App\Services\PromotionWriter;
use EasyCo\Extensibility\Hook;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * P5d: the four promotion hooks (promotion.created / updated / activated / deactivated) fired by PromotionWriter AFTER the commit, never for a refused or no-change write, with the
 * right arguments, and a throwing listener that neither breaks nor undoes the write. Shipping's hooks are the pattern; the one difference is the containment (see the writer's fireContained()).
 */
class PromotionHooksTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    /** @var list<array{0: string, 1: array<int, mixed>, 2: int}> hook name, arguments, the transaction level the listener ran at */
    private array $calls = [];

    private function record(string $hook): void
    {
        Hook::action($hook, function (...$args) use ($hook): void {
            $this->calls[] = [$hook, $args, DB::transactionLevel()];
        });
    }

    private function names(): array
    {
        return array_column($this->calls, 0);
    }

    private function state(array $over = []): array
    {
        return $over + ['code' => 'HK'.(++self::$n), 'discount_type' => 'percentage', 'percentage' => '10', 'is_active' => true, 'scopes' => []];
    }

    private function make(array $over = []): PromotionModel
    {
        $promotion = app(PromotionWriter::class)->create($this->state($over));

        return PromotionModel::query()->findOrFail($promotion->id());
    }

    // ---- the four hooks, once each, after the commit ----------------------------------------------------------------

    /** ★ create fires `promotion.created` once with the promotion, AFTER the commit (the listener runs at the test's own level, outside the writer's transaction) */
    public function test_create_fires_created_once_after_commit(): void
    {
        foreach (['promotion.created', 'promotion.updated', 'promotion.activated', 'promotion.deactivated'] as $hook) {
            $this->record($hook);
        }
        $baseline = DB::transactionLevel();

        $promotion = app(PromotionWriter::class)->create($this->state(['code' => 'hook-created']));

        $this->assertSame(['promotion.created'], $this->names());
        [, $args, $level] = $this->calls[0];
        $this->assertInstanceOf(Promotion::class, $args[0]);
        $this->assertSame((string) $promotion->id(), (string) $args[0]->id());
        $this->assertSame($baseline, $level, 'the hook ran outside the writer transaction');
    }

    /** ★ the listener of the created hook can read the saved row (it is committed) */
    public function test_a_listener_sees_the_committed_row(): void
    {
        $seen = null;
        Hook::action('promotion.created', function (Promotion $promotion) use (&$seen): void {
            $seen = PromotionModel::query()->where('code', $promotion->code())->exists();
        });

        app(PromotionWriter::class)->create($this->state(['code' => 'committed-row']));

        $this->assertTrue($seen);
    }

    /** ★ update fires `promotion.updated` once, and `$before` carries the OLD values */
    public function test_update_fires_updated_once_with_the_old_values(): void
    {
        $row = $this->make(['minimum_spend' => '10']);
        $this->record('promotion.updated');

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '20', 'is_active' => true]));

        $this->assertSame(['promotion.updated'], $this->names());
        [, $args] = $this->calls[0];
        $this->assertSame(2000, $args[0]->minimumSpend()->minorValue(), 'the NEW value is on the promotion');
        $this->assertSame('10.00', $args[1]['minimum_spend'], '$before carries the OLD value');
        $this->assertSame('20.00', $args[0]->minimumSpend()->decimalValue());
    }

    /** ★ switching off through setActive fires only `promotion.deactivated`, once, after the commit */
    public function test_setactive_fires_the_status_hook_once(): void
    {
        $row = $this->make();
        foreach (['promotion.created', 'promotion.updated', 'promotion.activated', 'promotion.deactivated'] as $hook) {
            $this->record($hook);
        }
        $baseline = DB::transactionLevel();

        app(PromotionWriter::class)->setActive((string) $row->id, false);

        $this->assertSame(['promotion.deactivated'], $this->names());
        $this->assertSame($baseline, $this->calls[0][2], 'after the commit');
        $this->assertFalse($this->calls[0][1][0]->isActive());
    }

    public function test_switching_on_fires_activated(): void
    {
        $row = $this->make(['is_active' => false]);
        $this->record('promotion.activated');

        app(PromotionWriter::class)->setActive((string) $row->id, true);

        $this->assertSame(['promotion.activated'], $this->names());
    }

    /** ★ an update that flips the status fires `updated` AND the status hook, once each */
    public function test_an_update_that_flips_the_status_fires_updated_and_the_status_hook_once_each(): void
    {
        $row = $this->make();
        $this->record('promotion.updated');
        $this->record('promotion.deactivated');
        $this->record('promotion.activated');

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['is_active' => false]));

        $this->assertSame(['promotion.updated', 'promotion.deactivated'], $this->names());
    }

    // ---- no hook where nothing happened --------------------------------------------------------------------------

    /** ★ a refused write (an invalid field) fires nothing, for create and for update */
    public function test_no_hook_for_a_refused_write(): void
    {
        $row = $this->make();
        foreach (['promotion.created', 'promotion.updated', 'promotion.activated', 'promotion.deactivated'] as $hook) {
            $this->record($hook);
        }

        try {
            app(PromotionWriter::class)->create($this->state(['code' => 'refused', 'minimum_spend' => '50', 'maximum_spend' => '10']));
            $this->fail('expected a refusal');
        } catch (PromotionInvalidException) {
            // expected
        }

        try {
            app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '50', 'maximum_spend' => '10']));
            $this->fail('expected a refusal');
        } catch (PromotionInvalidException) {
            // expected
        }

        $this->assertSame([], $this->names());
    }

    /** ★ an update that changes nothing fires nothing, and a switch to the status it already has fires nothing */
    public function test_no_hook_for_a_no_change_update_or_a_switch_to_the_same_status(): void
    {
        $row = $this->make(['minimum_spend' => '10']);
        foreach (['promotion.updated', 'promotion.activated', 'promotion.deactivated'] as $hook) {
            $this->record($hook);
        }

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '10.00', 'is_active' => true]));
        app(PromotionWriter::class)->setActive((string) $row->id, true);

        $this->assertSame([], $this->names());
    }

    // ---- a throwing listener -----------------------------------------------------------------------------------

    /** ★ a listener that throws does not break the write and does not roll it back: the create still returns the promotion and the row is there */
    public function test_a_throwing_created_listener_does_not_break_or_roll_back_the_write(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        Hook::action('promotion.created', function (): void {
            throw new RuntimeException('listener blew up');
        });

        $promotion = app(PromotionWriter::class)->create($this->state(['code' => 'survives']));

        $this->assertSame('survives', $promotion->code());
        $this->assertTrue(PromotionModel::query()->where('code', 'survives')->exists(), 'the row was committed and stays');
        $this->assertSame(1, DB::table('activity_log')->where('entity_type', 'promotion')->where('action', 'created')->count(), 'and so is its audit entry');
    }

    /** ★ the same for update and for the status switch: the change stays saved */
    public function test_a_throwing_listener_on_update_or_status_does_not_undo_the_change(): void
    {
        $row = $this->make();
        Hook::action('promotion.updated', fn () => throw new RuntimeException('updated blew up'));
        Hook::action('promotion.deactivated', fn () => throw new RuntimeException('deactivated blew up'));

        app(PromotionWriter::class)->update((string) $row->id, $this->state(['minimum_spend' => '7', 'is_active' => false]));

        $fresh = PromotionModel::query()->findOrFail($row->id);
        $this->assertSame(700, (int) $fresh->minimum_spend_amount_minor, 'the field change stays');
        $this->assertSame('inactive', $fresh->status, 'and the status change stays');
    }

    // ---- the document -----------------------------------------------------------------------------------------------

    /** the Hook Reference has the four rows (the rule: a new hook is a row in the same commit) */
    public function test_the_hook_reference_lists_the_four_promotion_hooks(): void
    {
        $doc = (string) file_get_contents(base_path('packages/EasyCo/documents/extensibility-design-and-hooks.md'));

        foreach (['promotion.created', 'promotion.updated', 'promotion.activated', 'promotion.deactivated'] as $hook) {
            $this->assertStringContainsString('| `'.$hook.'` | Action |', $doc, $hook);
        }
    }
}
