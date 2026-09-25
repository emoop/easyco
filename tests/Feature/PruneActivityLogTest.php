<?php

namespace Tests\Feature;

use App\Models\ActivityLogModel;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the real, production `activity-log:prune` command — a real
 * dated-row fixture, not just that the command exits 0.
 */
class PruneActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function logRow(string $id, \DateTimeInterface $occurredAt): ActivityLogModel
    {
        return ActivityLogModel::create([
            'entity_type' => 'product',
            'entity_id' => $id,
            'action' => 'created',
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_it_deletes_rows_older_than_the_configured_retention_and_keeps_newer_ones(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        app(SiteSettingsRepository::class)->set('admin.activity_log_retention_months', '6');

        $old = $this->logRow('old', now()->subMonths(7));
        $borderline = $this->logRow('borderline', now()->subMonths(6)->subDay());
        $recent = $this->logRow('recent', now()->subMonths(1));

        $this->artisan('activity-log:prune')->assertExitCode(0);

        $this->assertNull(ActivityLogModel::find($old->id));
        $this->assertNull(ActivityLogModel::find($borderline->id));
        $this->assertNotNull(ActivityLogModel::find($recent->id));
    }

    public function test_it_skips_entirely_when_the_log_is_disabled(): void
    {
        app(SiteSettingsRepository::class)->forget('admin.activity_log_enabled');

        $old = $this->logRow('old', now()->subYears(2));

        $this->artisan('activity-log:prune')->assertExitCode(0);

        // Nothing touched — the command's own documented "skip
        // entirely while disabled" behavior, not a silent no-op delete.
        $this->assertNotNull(ActivityLogModel::find($old->id));
    }

    public function test_it_defaults_to_twelve_months_retention_when_the_setting_was_never_configured(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $old = $this->logRow('old', now()->subMonths(13));
        $recent = $this->logRow('recent', now()->subMonths(11));

        $this->artisan('activity-log:prune')->assertExitCode(0);

        $this->assertNull(ActivityLogModel::find($old->id));
        $this->assertNotNull(ActivityLogModel::find($recent->id));
    }

    /**
     * catalog-domain-design.md §3.19.10's second decision: a deletion
     * snapshot is the only remaining record of what a hard delete
     * destroyed, so the age cutoff never touches it — even when every
     * OTHER old row is pruned in the same run.
     */
    public function test_it_never_prunes_deletion_snapshots(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        app(SiteSettingsRepository::class)->set('admin.activity_log_retention_months', '1');

        $deletion = ActivityLogModel::create([
            'entity_type' => 'variation',
            'entity_id' => '123',
            'action' => ActivityLogger::ACTION_DELETED,
            'old_value' => json_encode(['variation_sku' => 'SKU-X']),
            'occurred_at' => now()->subYears(5),
        ]);

        $old = $this->logRow('old', now()->subYears(5));

        $this->artisan('activity-log:prune')->assertExitCode(0);

        $this->assertNotNull(ActivityLogModel::find($deletion->id));
        $this->assertNull(ActivityLogModel::find($old->id));
    }

    /**
     * The gate exception (§3.19.10's first decision) asserted at the
     * logger itself: a deletion snapshot is written while the log is OFF,
     * and nothing else is.
     */
    public function test_a_deletion_snapshot_is_written_even_while_the_log_is_disabled(): void
    {
        app(SiteSettingsRepository::class)->forget('admin.activity_log_enabled');

        app(ActivityLogger::class)->logDeleted('variation', '9', ['variation_sku' => 'SKU-X']);
        app(ActivityLogger::class)->logCreated('product', '9');

        $row = ActivityLogModel::where('action', ActivityLogger::ACTION_DELETED)->sole();

        $this->assertSame('variation', $row->entity_type);
        $this->assertSame('9', $row->entity_id);
        $this->assertNull($row->new_value);
        $this->assertSame('SKU-X', json_decode((string) $row->old_value, true)['variation_sku']);

        // The ordinary entry was suppressed — the exception is exactly one
        // method wide.
        $this->assertSame(1, ActivityLogModel::count());
    }
}
