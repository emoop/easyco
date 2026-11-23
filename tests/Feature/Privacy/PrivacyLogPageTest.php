<?php

namespace Tests\Feature\Privacy;

use App\Filament\Pages\PrivacyLog;
use App\Privacy\ForgetLogPresenter;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyEraser;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacySettings;
use App\Privacy\PrivacySource;
use App\Privacy\StaffActor;
use App\Privacy\SuppressionList;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md D6, section 7): «Поверителност → Дневник»: the permission, the rows listed, the clear view decided on read, the lookup by hash, the manual steps and the query count.
 */
class PrivacyLogPageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    /** @param array<string, mixed> $o */
    private function erase(array $o = []): PrivacyRequestModel
    {
        $email = $o['email'] ?? 'me@example.com';

        return PrivacyRequestModel::query()->create($o + [
            'kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => $email, 'email_hash' => (string) app(PrivacyHash::class)->email($email), 'phone' => '+359888111111',
            'reason' => 'Asked by email', 'staff_id' => 1, 'staff_name' => 'Admin Tester', 'completed_at' => now()->subDay(), 'clear_until' => now()->addDays(29),
            'actions' => ['account_deleted' => true, 'addresses_deleted' => 2],
        ]);
    }

    /** @param list<string> $labels */
    private function steps(array $labels): void
    {
        app(PrivacySettings::class)->saveManualSteps($labels);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(PrivacyLog::class)->loadTable();
    }

    // ---- the permission ------------------------------------------------------------------------------------------------------------

    /** ★ the page and its actions need PRIVACY_MANAGE on the server */
    public function test_the_page_and_the_actions_need_privacy_manage(): void
    {
        $row = $this->erase();
        $this->steps(['Removed from Facebook']);
        $key = app(PrivacySettings::class)->manualStepList()[0]['key'];

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW, Permission::ORDER_VIEW]);
        $this->get('/admin/privacy-log')->assertForbidden();

        $this->actingAsStaff('Administrator');
        $page = Livewire::test(PrivacyLog::class)->loadTable();
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);

        foreach ([fn () => $page->call('toggleStep', $row->getKey(), $key, true), fn () => $page->set('lookupEmail', 'me@example.com')->call('lookup')] as $try) {
            try {
                $try();
            } catch (\Throwable) {
                // refused (403)
            }
        }

        $this->assertSame([], $row->fresh()->manual_steps ?? [], 'nothing was ticked');
        $this->assertNull($page->get('lookupResult'));
    }

    public function test_the_item_sits_in_the_privacy_group(): void
    {
        $this->assertSame(\App\Filament\NavigationGroup::PRIVACY, PrivacyLog::getNavigationGroup());
        $this->assertSame(__('navigation.groups.privacy'), \App\Filament\NavigationGroup::PRIVACY->getLabel());
    }

    // ---- the rows ------------------------------------------------------------------------------------------------------------------

    public function test_only_done_processing_and_failed_erase_rows_are_listed_newest_first_and_without_a_source_column(): void
    {
        $this->actingAsStaff('Administrator');
        $old = $this->erase(['email' => 'old@example.com', 'completed_at' => now()->subDays(20)]);
        $new = $this->erase(['email' => 'new@example.com', 'completed_at' => now()->subDay()]);
        $processing = $this->erase(['email' => 'p@example.com', 'status' => 'processing', 'completed_at' => null, 'clear_until' => null]);
        $failed = $this->erase(['email' => 'f@example.com', 'status' => 'failed', 'completed_at' => null, 'clear_until' => null, 'created_at' => now()->subDays(3)]);
        // never listed: the confirmation rows and an export
        $this->erase(['email' => 'w@example.com', 'status' => 'awaiting_confirmation', 'completed_at' => null, 'reason' => null]);
        $this->erase(['email' => 'c@example.com', 'status' => 'confirmed', 'completed_at' => null, 'reason' => null]);
        $this->erase(['email' => 'x@example.com', 'status' => 'expired', 'completed_at' => null, 'reason' => null]);
        $this->erase(['email' => 'e@example.com', 'kind' => 'export', 'reason' => null]);

        $page = $this->page();
        $ids = $page->instance()->getTableRecords()->pluck('id')->all();

        $this->assertSame([$processing->getKey(), $new->getKey(), $failed->getKey(), $old->getKey()], $ids, 'newest first by completed_at, else created_at');
        $html = $page->html();
        $this->assertStringContainsString(__('privacy_admin.log.status.processing'), $html);
        $this->assertStringContainsString(__('privacy_admin.log.status.failed'), $html);
        $this->assertSame(['completed_at', 'contact', 'staff_name', 'reason', 'actions_done', 'manual', 'clear'], array_keys($page->instance()->getTable()->getColumns()), 'no «Източник» column');
    }

    // ---- the clear view, decided on read -------------------------------------------------------------------------------------------

    /** ★ the email and the phone are shown only while clear_until is in the future, even when the nightly nulling has not run */
    public function test_the_clear_view_is_decided_on_read(): void
    {
        $this->actingAsStaff('Administrator');
        $this->erase(['email' => 'visible@example.com', 'phone' => '+359111111111', 'clear_until' => now()->addDays(10)]);
        $this->erase(['email' => 'past@example.com', 'phone' => '+359222222222', 'clear_until' => now()->subMinute(), 'completed_at' => now()->subDays(31)]);
        $this->erase(['email' => 'nulled@example.com', 'phone' => null, 'clear_until' => now()->addDays(5)])->update(['email' => null]);

        $html = $this->page()->html();

        $this->assertStringContainsString('visible@example.com', $html);
        $this->assertStringContainsString('+359111111111', $html);
        $this->assertStringNotContainsString('past@example.com', $html, 'past clear_until, the command has not run: already «само хеш»');
        $this->assertStringNotContainsString('+359222222222', $html);
        $this->assertSame(2, substr_count($html, __('privacy_admin.log.hash_only')), 'the past row and the nulled one');
        $this->assertStringContainsString(__('privacy_admin.log.clear_expired'), $html);
    }

    public function test_the_remaining_days_are_the_ceil_and_amber_at_seven_or_less(): void
    {
        $row = $this->erase(['clear_until' => now()->addDays(7)->subHour()]);
        $view = ForgetLogPresenter::clearView($row);
        $this->assertSame(7, $view['days']);
        $this->assertTrue($view['amber']);

        $row->clear_until = now()->addDays(7)->addHour();
        $view = ForgetLogPresenter::clearView($row);
        $this->assertSame(8, $view['days']);
        $this->assertFalse($view['amber']);

        $row->clear_until = now()->addMinutes(5);
        $this->assertSame(1, ForgetLogPresenter::clearView($row)['days'], 'a few minutes left is one day');

        $row->clear_until = now();
        $this->assertFalse(ForgetLogPresenter::clearView($row)['visible'], 'ended');
        $this->assertNull(ForgetLogPresenter::email($row));
    }

    // ---- what was done automatically -------------------------------------------------------------------------------------------------

    public function test_the_actions_are_sentences_and_a_listener_key_is_plain_escaped_text_whatever_its_type(): void
    {
        $this->actingAsStaff('Administrator');
        $this->erase(['actions' => [
            'account_deleted' => true, 'addresses_deleted' => 3, 'cod_blocks_kept' => '2027-02-01 10:00:00', 'hook_customer_erased' => true,
            'mailchimp.removed' => true, 'facebook_audience' => '<b>big</b> & <script>x</script>', 'crm_count' => 12,
        ]]);

        $html = $this->page()->html();

        $this->assertStringContainsString(__('privacy_admin.log.action.account_deleted'), $html);
        $this->assertStringContainsString(__('privacy_admin.log.action.addresses_deleted', ['value' => 3]), $html);
        $this->assertStringContainsString(__('privacy_admin.log.action.cod_blocks_kept', ['value' => '2027-02-01 10:00:00']), $html);
        $this->assertStringContainsString('mailchimp.removed: true', $html);
        $this->assertStringContainsString('crm_count: 12', $html);
        $this->assertStringContainsString('facebook_audience: &lt;b&gt;big&lt;/b&gt; &amp; &lt;script&gt;x&lt;/script&gt;', $html, 'escaped');
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringNotContainsString('<b>big</b>', $html);
    }

    // ---- the lookup ----------------------------------------------------------------------------------------------------------------

    public function test_the_lookup_finds_an_erased_email_through_the_hash_case_insensitively_with_the_suppression_state(): void
    {
        Queue::fake();
        $this->actingAsStaff('Administrator');
        $this->insertErasedThroughTheEraser('me@example.com');

        $page = Livewire::test(PrivacyLog::class)->set('lookupEmail', '  ME@Example.COM ')->call('lookup');
        $result = $page->get('lookupResult');

        $this->assertSame('found', $result['state']);
        $this->assertTrue($result['suppressed']);
        $this->assertNull($result['lifted']);
        $this->assertStringNotContainsString('example.com', json_encode($result), 'the answer holds no email');
        $page->assertSee(__('privacy_admin.log.lookup.suppressed'));

        // lifted by a new, confirmed signup
        app(SuppressionList::class)->lift('me@example.com', 'Signed up again with a confirmed double opt-in');
        $page = Livewire::test(PrivacyLog::class)->set('lookupEmail', 'me@example.com')->call('lookup');
        $result = $page->get('lookupResult');
        $this->assertSame('found', $result['state']);
        $this->assertFalse($result['suppressed']);
        $this->assertNotNull($result['lifted']);

        // none: an email nobody forgot, and an email that only has a confirmation row
        $this->assertSame('none', Livewire::test(PrivacyLog::class)->set('lookupEmail', 'nobody@example.com')->call('lookup')->get('lookupResult')['state']);
        $this->erase(['email' => 'waiting@example.com', 'status' => 'confirmed', 'completed_at' => null, 'reason' => null]);
        $this->assertSame('none', Livewire::test(PrivacyLog::class)->set('lookupEmail', 'waiting@example.com')->call('lookup')->get('lookupResult')['state']);
    }

    public function test_a_malformed_email_gets_a_plain_message(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['', '   ', 'nope', 'a b@example.com', 'a@b', "x@example.com\nbcc@evil.test"] as $typed) {
            $page = Livewire::test(PrivacyLog::class)->set('lookupEmail', $typed)->call('lookup');
            $this->assertSame('invalid', $page->get('lookupResult')['state'], json_encode($typed));
            $page->assertSee(__('privacy_admin.log.lookup.invalid'));
        }
    }

    /** ★ the typed email is never stored, logged or put in the URL */
    public function test_the_typed_email_appears_nowhere(): void
    {
        Queue::fake();
        $this->actingAsStaff('Administrator');
        $this->insertErasedThroughTheEraser('secret.person@example.com');
        $before = ['requests' => DB::table('privacy_requests')->count(), 'journal' => DB::table('activity_log')->count(), 'suppressions' => DB::table('privacy_suppressions')->count()];

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = json_encode([$message->message, $message->context]);
        });

        $page = Livewire::test(PrivacyLog::class)->set('lookupEmail', 'secret.person@example.com')->call('lookup');

        $this->assertSame($before, ['requests' => DB::table('privacy_requests')->count(), 'journal' => DB::table('activity_log')->count(), 'suppressions' => DB::table('privacy_suppressions')->count()]);
        $this->assertStringNotContainsString('secret.person', implode('', $logged), 'not logged');
        $this->assertStringNotContainsString('secret.person', json_encode($page->get('lookupResult')), 'not in the answer');
        $this->assertSame([], (new \ReflectionProperty(PrivacyLog::class, 'lookupEmail'))->getAttributes(), 'the field is not bound to the URL');
        $this->assertStringNotContainsString('secret.person', (string) ($page->effects['path'] ?? ''), 'not in the URL');
    }

    public function test_without_the_hash_key_in_production_the_page_opens_and_the_lookup_says_it_is_unavailable(): void
    {
        $this->actingAsStaff('Administrator');
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            $this->get('/admin/privacy-log')->assertOk();
            $page = Livewire::test(PrivacyLog::class)->set('lookupEmail', 'me@example.com')->call('lookup');
            $this->assertSame('unavailable', $page->get('lookupResult')['state']);
            $page->assertSee('PRIVACY_HASH_KEY');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function insertErasedThroughTheEraser(string $email): void
    {
        app(PrivacyEraser::class)->erase($email, PrivacySource::ADMIN, new StaffActor(1, 'Admin Tester'), 'Asked by email');
    }

    // ---- the manual steps ----------------------------------------------------------------------------------------------------------

    public function test_the_list_starts_empty_and_there_are_no_checkboxes_without_steps(): void
    {
        $this->actingAsStaff('Administrator');
        $this->erase();

        $this->assertSame([], app(PrivacySettings::class)->manualStepList(), 'the default is an empty list');
        $html = $this->page()->html();

        $this->assertStringNotContainsString('toggleStep', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function test_a_tick_stores_key_time_and_staff_and_an_untick_removes_it(): void
    {
        $staff = $this->actingAsStaff('Administrator');
        $row = $this->erase();
        $this->steps(['Removed from Facebook', 'Removed from Mailchimp']);
        [$a, $b] = array_column(app(PrivacySettings::class)->manualStepList(), 'key');
        $page = $this->page();

        $page->call('toggleStep', $row->getKey(), $a, true);
        $page->call('toggleStep', $row->getKey(), $b, true);

        $steps = $row->fresh()->manual_steps;
        $this->assertSame([$a, $b], array_column($steps, 'key'), 'both are kept');
        $this->assertSame((int) $staff->getKey(), $steps[0]['staff_id']);
        $this->assertNotNull($steps[0]['done_at']);
        $this->assertSame(['key', 'done_at', 'staff_id'], array_keys($steps[0]));

        $page->call('toggleStep', $row->getKey(), $a, false);
        $this->assertSame([$b], array_column($row->fresh()->manual_steps, 'key'), 'the unticked one is gone, the other stays');

        // ticking twice never duplicates
        $page->call('toggleStep', $row->getKey(), $b, true);
        $this->assertSame([$b], array_column($row->fresh()->manual_steps, 'key'));
    }

    public function test_only_current_keys_are_accepted_and_only_for_an_erase_row(): void
    {
        $this->actingAsStaff('Administrator');
        $row = $this->erase();
        $export = $this->erase(['kind' => 'export', 'email' => 'e@example.com', 'reason' => null]);
        $this->steps(['Removed from Facebook']);
        $key = app(PrivacySettings::class)->manualStepList()[0]['key'];
        $page = $this->page();

        $page->call('toggleStep', $row->getKey(), 'not-a-step', true);
        $page->call('toggleStep', $row->getKey(), 'S'.str_repeat('x', 80), true);
        $page->call('toggleStep', $export->getKey(), $key, true);
        $page->call('toggleStep', 999999, $key, true);

        $this->assertSame([], $row->fresh()->manual_steps ?? []);
        $this->assertSame([], $export->fresh()->manual_steps ?? []);
    }

    public function test_the_tick_is_a_read_modify_write_under_a_row_lock(): void
    {
        $this->actingAsStaff('Administrator');
        $row = $this->erase();
        $this->steps(['One', 'Two']);
        $key = app(PrivacySettings::class)->manualStepList()[0]['key'];
        $page = $this->page();
        $locked = [];
        DB::listen(function ($query) use (&$locked): void {
            str_contains($query->sql, 'from `privacy_requests`') && str_contains($query->sql, 'for update') && $locked[] = $query->sql;
        });

        $page->call('toggleStep', $row->getKey(), $key, true);

        $this->assertCount(1, $locked, 'the row is read FOR UPDATE inside the transaction');
    }

    public function test_a_step_removed_from_the_settings_is_not_shown_but_its_flag_stays_in_the_row(): void
    {
        $this->actingAsStaff('Administrator');
        $row = $this->erase();
        $this->steps(['Removed from Facebook', 'Removed from Mailchimp']);
        [$a, $b] = array_column(app(PrivacySettings::class)->manualStepList(), 'key');
        $this->page()->call('toggleStep', $row->getKey(), $a, true)->call('toggleStep', $row->getKey(), $b, true);

        app(PrivacySettings::class)->saveManualStepList([['key' => $a, 'label' => 'Removed from Facebook']]);
        $html = $this->page()->html();

        $this->assertStringContainsString('Removed from Facebook', $html);
        $this->assertStringNotContainsString('Removed from Mailchimp', $html);
        $this->assertSame([$a, $b], array_column($row->fresh()->manual_steps, 'key'), 'the stored flag of the removed step stays');
    }

    // ---- the query count ------------------------------------------------------------------------------------------------------------

    /** ★ a constant number of queries per page: 5 rows or 25 */
    public function test_the_query_count_does_not_grow_with_the_rows(): void
    {
        $this->actingAsStaff('Administrator');
        $this->steps(['One', 'Two', 'Three']);
        $count = function (): int {
            $page = $this->page();
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $page->call('$refresh');

            return $n;
        };

        foreach (range(1, 5) as $i) {
            $this->erase(['email' => "p{$i}@example.com", 'manual_steps' => [['key' => app(PrivacySettings::class)->manualStepList()[0]['key'], 'done_at' => now()->toIso8601String(), 'staff_id' => 1]]]);
        }
        $small = $count();

        foreach (range(6, 25) as $i) {
            $this->erase(['email' => "p{$i}@example.com", 'actions' => ['account_deleted' => true, 'x'.$i => $i]]);
        }
        $large = $count();

        fwrite(STDERR, "\n[query-count] privacy log: 5 rows {$small} queries, 25 rows {$large}\n");
        $this->assertSame($small, $large);
    }
}
