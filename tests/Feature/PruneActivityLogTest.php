<?php

namespace Tests\Feature;

use App\Models\ActivityLogModel;
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
}
