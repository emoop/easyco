<?php

namespace Tests\Feature\Privacy;

use App\Filament\Widgets\ForgetRequestsTile;
use App\Privacy\ForgetPending;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacySettings;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6, section 7): the «Табло» tile «Заявка за забравяне»: only for PRIVACY_MANAGE, only when something waits, judged against the CURRENT list of steps.
 */
class ForgetTileTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ForgetPending::forget();
    }

    protected function tearDown(): void
    {
        ForgetPending::forget();
        parent::tearDown();
    }

    /** @param array<string, mixed> $o */
    private function erase(string $email, array $o = []): PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->create($o + [
            'kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => $email, 'email_hash' => (string) app(PrivacyHash::class)->email($email),
            'reason' => 'Asked', 'completed_at' => now()->subDay(), 'clear_until' => now()->addDays(20),
        ]);
    }

    /** @return list<string> the step keys */
    private function steps(array $labels): array
    {
        app(PrivacySettings::class)->saveManualSteps($labels);
        ForgetPending::forget();

        return array_column(app(PrivacySettings::class)->manualStepList(), 'key');
    }

    private function tick(PrivacyRequestModel $row, array $keys): void
    {
        $row->manual_steps = array_map(fn (string $key): array => ['key' => $key, 'done_at' => now()->toIso8601String(), 'staff_id' => 1], $keys);
        $row->save();
        ForgetPending::forget();
    }

    public function test_the_count_and_the_person_with_the_fewest_days_are_right(): void
    {
        [$a, $b] = $this->steps(['Facebook', 'Mailchimp']);
        $this->erase('late@example.com', ['clear_until' => now()->addDays(25)]);
        $this->erase('soon@example.com', ['clear_until' => now()->addDays(3)->addHour()]);
        $partly = $this->erase('partly@example.com', ['clear_until' => now()->addDays(12)]);
        $this->tick($partly, [$a]);

        $summary = ForgetPending::summary();

        $this->assertSame(3, $summary['count'], 'a partly ticked row still waits');
        $this->assertSame('soon@example.com', $summary['email']);
        $this->assertSame(4, $summary['days'], 'ceil of 3 days and an hour');
    }

    public function test_a_row_past_clear_until_and_a_row_with_all_steps_done_are_not_counted(): void
    {
        [$a, $b] = $this->steps(['Facebook', 'Mailchimp']);
        $this->erase('past@example.com', ['clear_until' => now()->subMinute(), 'completed_at' => now()->subDays(31)]);
        $finished = $this->erase('finished@example.com');
        $this->tick($finished, [$a, $b]);
        $this->erase('processing@example.com', ['status' => 'processing', 'completed_at' => null]);
        $this->erase('export@example.com', ['kind' => 'export', 'reason' => null]);
        $this->erase('confirmed@example.com', ['status' => 'confirmed', 'completed_at' => null, 'reason' => null]);
        $this->assertNull(ForgetPending::summary(), 'nothing is pending');

        $waiting = $this->erase('waiting@example.com');
        ForgetPending::forget();
        $this->assertSame(1, ForgetPending::summary()['count']);
        $this->assertSame('waiting@example.com', ForgetPending::summary()['email']);
    }

    public function test_a_step_added_later_makes_a_finished_row_wait_again_and_a_removed_step_releases_it(): void
    {
        [$a] = $this->steps(['Facebook']);
        $row = $this->erase('me@example.com');
        $this->tick($row, [$a]);
        $this->assertNull(ForgetPending::summary());

        $this->steps(['Facebook', 'Mailchimp']);
        $this->assertSame(1, ForgetPending::summary()['count'], 'judged against the CURRENT list');

        app(PrivacySettings::class)->saveManualStepList([['key' => $a, 'label' => 'Facebook']]);
        ForgetPending::forget();
        $this->assertNull(ForgetPending::summary());
    }

    /** an empty step list means nothing is pending: no tile */
    public function test_an_empty_list_shows_no_tile(): void
    {
        $this->erase('me@example.com');
        $this->actingAsStaff('Administrator');

        $this->assertNull(ForgetPending::summary());
        $this->assertFalse(ForgetRequestsTile::canView());
    }

    /** ★ not visible without PRIVACY_MANAGE, not visible when nothing is pending, visible otherwise */
    public function test_visibility_needs_privacy_manage_and_a_pending_row(): void
    {
        $this->steps(['Facebook']);

        $this->actingAsStaff('Administrator');
        $this->assertFalse(ForgetRequestsTile::canView(), 'nothing is pending');

        $this->erase('me@example.com');
        ForgetPending::forget();
        $this->assertTrue(ForgetRequestsTile::canView());

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW, Permission::ORDER_VIEW]);
        $this->assertFalse(ForgetRequestsTile::canView(), 'a pending row, but no PRIVACY_MANAGE');

        $this->actingAsStaff('Manager');
        $this->assertFalse(ForgetRequestsTile::canView(), 'Manager has no PRIVACY_MANAGE');
    }

    public function test_the_dashboard_shows_the_tile_with_the_count_the_person_and_the_link_only_when_it_applies(): void
    {
        $this->steps(['Facebook']);
        $this->actingAsStaff('Administrator');

        $this->assertStringNotContainsString(__('privacy_admin.tile.heading'), (string) $this->get('/admin')->assertOk()->getContent());

        $this->erase('person@example.com', ['clear_until' => now()->addDays(2)->addHour()]);
        ForgetPending::forget();
        $html = (string) $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString(__('privacy_admin.tile.heading'), $html);
        $this->assertStringContainsString('person@example.com', $html);
        $this->assertStringContainsString(trans_choice('privacy_admin.log.clear_days', 3, ['count' => 3]), $html);
        $this->assertStringContainsString('/admin/privacy-log', $html);
        $this->assertStringContainsString(__('privacy_admin.tile.open'), $html);

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        ForgetPending::forget();
        $this->assertStringNotContainsString(__('privacy_admin.tile.heading'), (string) $this->get('/admin')->assertOk()->getContent());
    }

    public function test_it_reads_no_hash_and_works_without_the_hash_key(): void
    {
        $this->steps(['Facebook']);
        $this->erase('me@example.com');
        ForgetPending::forget();
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            $this->assertSame(1, ForgetPending::summary()['count']);
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
