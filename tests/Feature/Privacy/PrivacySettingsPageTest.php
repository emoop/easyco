<?php

namespace Tests\Feature\Privacy;

use App\Filament\Pages\Settings\PrivacySettingsPage;
use App\Privacy\OrderRetention;
use App\Privacy\PrivacySettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md section 7): «Поверителност: настройки»: the permission, the ranges, the steps and their stable keys, the confirmation that lowering the order retention needs (with the
 * right count), and that the saved values are the ones the anonymiser and `mail:prune-log` use.
 */
class PrivacySettingsPageTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
    }

    private function save(\Livewire\Features\SupportTesting\Testable $page): \Livewire\Features\SupportTesting\Testable
    {
        return $page->callAction(TestAction::make('save')->schemaComponent('form-actions', schema: 'content'));
    }

    private function mountSave(\Livewire\Features\SupportTesting\Testable $page): \Livewire\Features\SupportTesting\Testable
    {
        return $page->mountAction(TestAction::make('save')->schemaComponent('form-actions', schema: 'content'));
    }

    private function settings(): PrivacySettings
    {
        return app(PrivacySettings::class);
    }

    // ---- the permission ------------------------------------------------------------------------------------------------------------

    /** ★ the page needs PRIVACY_MANAGE: open, and every request; the save cannot be called directly */
    public function test_the_page_needs_privacy_manage_on_the_server(): void
    {
        $this->actingAsCustomRole([Permission::SETTINGS_MANAGE, Permission::CUSTOMER_VIEW]);
        $this->get('/admin/privacy-settings')->assertForbidden();

        $this->actingAsStaff('Administrator');
        $page = Livewire::test(PrivacySettingsPage::class);
        $this->get('/admin/privacy-settings')->assertOk();

        // the save is a protected method that only the confirmed action calls: a hand-made call is refused
        $threw = false;

        try {
            $page->set('data.retention_years', 1)->call('persist');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'persist() cannot be called from the browser');
        $this->assertSame(10, $this->settings()->orderRetentionYears());

        // a user who lost the permission is refused on the next request
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);

        try {
            $this->save($page->set('data.retention_years', 12));
        } catch (\Throwable) {
            // 403
        }

        $this->assertSame(10, $this->settings()->orderRetentionYears());
    }

    public function test_the_item_sits_in_the_settings_area(): void
    {
        $this->assertSame(\App\Filament\NavigationGroup::ADMIN, PrivacySettingsPage::getNavigationGroup());
        $this->assertSame(37, PrivacySettingsPage::getNavigationSort());
    }

    // ---- the values -----------------------------------------------------------------------------------------------------------------

    public function test_the_defaults_and_the_ranges(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(PrivacySettingsPage::class)->assertFormSet(['retention_years' => 10, 'mail_log_days' => 365, 'steps' => []]);

        foreach ([['retention_years', 0], ['retention_years', 31], ['retention_years', 'abc'], ['mail_log_days', 29], ['mail_log_days', 3651]] as [$field, $value]) {
            $page = Livewire::test(PrivacySettingsPage::class)->fillForm([$field => $value]);

            try {
                $this->save($page);
            } catch (\Throwable) {
                // the validation refused it
            }
        }

        $this->assertSame(10, $this->settings()->orderRetentionYears());
        $this->assertSame(365, $this->settings()->mailLogRetentionDays());

        foreach ([[1, 30], [30, 3650]] as [$years, $days]) {
            $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => $years, 'mail_log_days' => $days]);
            $this->save($page);
            $this->assertSame([$years, $days], [$this->settings()->orderRetentionYears(), $this->settings()->mailLogRetentionDays()]);
        }
    }

    // ---- the steps -----------------------------------------------------------------------------------------------------------------

    public function test_steps_are_limited_to_ten_labels_of_1_to_80_plain_characters_without_duplicates(): void
    {
        $this->actingAsStaff('Administrator');
        $items = fn (array $labels): array => array_map(fn (string $label): array => ['key' => null, 'label' => $label], $labels);

        foreach ([
            'eleven' => array_map(fn (int $i): string => "Step {$i}", range(1, 11)),
            'too long' => [str_repeat('я', 81)],
            'empty' => [''],
            'control character' => ["bad\x07label"],
            'bidi override' => ["bidi \u{202E}label"],
        ] as $name => $labels) {
            $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['steps' => $items($labels)]);
            $this->save($page);
            $this->assertSame([], $this->settings()->manualStepList(), $name.': nothing was saved');
        }

        // ten different labels of 80 characters are fine
        $ten = array_map(fn (int $i): string => "S{$i} ".str_repeat('я', 80 - mb_strlen("S{$i} ")), range(1, 10));
        $this->save(Livewire::test(PrivacySettingsPage::class)->fillForm(['steps' => $items($ten)]));
        $this->assertCount(10, $this->settings()->manualStepList());

        // duplicates are refused (and nothing else changes)
        $this->save(Livewire::test(PrivacySettingsPage::class)->fillForm(['steps' => $items(['Same', 'Same'])]));
        $this->assertCount(10, $this->settings()->manualStepList(), 'the saved list is still the ten');
    }

    /** the key is made when a step is added, never from the label, and survives a label edit */
    public function test_keys_are_stable_after_a_label_edit_and_never_made_from_the_label(): void
    {
        $this->actingAsStaff('Administrator');
        $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['steps' => [['key' => null, 'label' => 'Removed from Facebook'], ['key' => null, 'label' => 'Removed from Mailchimp']]]);
        $this->save($page);

        $first = $this->settings()->manualStepList();
        [$a, $b] = array_column($first, 'key');
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^s[0-9a-f]{8}$/', $a);
        $this->assertStringNotContainsString('facebook', strtolower($a), 'not made from the label');

        // edit the label of the first, keep the second, add a third
        $stored = $this->settings()->manualStepList();
        $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['steps' => [
            ['key' => $stored[0]['key'], 'label' => 'Removed from the Facebook audience'],
            ['key' => $stored[1]['key'], 'label' => 'Removed from Mailchimp'],
            ['key' => null, 'label' => 'Removed from the CRM'],
        ]]);
        $this->save($page);

        $second = $this->settings()->manualStepList();
        $this->assertSame([$a, $b], [$second[0]['key'], $second[1]['key']], 'the keys are kept');
        $this->assertSame('Removed from the Facebook audience', $second[0]['label']);
        $this->assertNotContains($second[2]['key'], [$a, $b]);

        // removed and added again = a NEW step with a new key
        $this->settings()->saveManualStepList([['key' => $a, 'label' => 'x1']]);
        $this->settings()->saveManualStepList([['key' => $a, 'label' => 'x1'], ['key' => null, 'label' => 'Removed from Mailchimp']]);
        $this->assertNotSame($b, $this->settings()->manualStepList()[1]['key']);
    }

    public function test_the_older_list_of_plain_labels_is_still_read_and_gets_real_keys_on_the_next_save(): void
    {
        app(SiteSettingsRepository::class)->set(PrivacySettings::MANUAL_STEPS_KEY, json_encode(['Old label one', 'Old label two'], JSON_UNESCAPED_UNICODE));

        $list = $this->settings()->manualStepList();
        $this->assertSame(['Old label one', 'Old label two'], array_column($list, 'label'));
        $this->assertSame($list, $this->settings()->manualStepList(), 'the derived keys are stable');

        $this->settings()->saveManualSteps(['Old label one', 'Old label two', 'New']);
        $saved = $this->settings()->manualStepList();
        $this->assertSame($list[0]['key'], $saved[0]['key'], 'an existing label keeps its key');
        $this->assertMatchesRegularExpression('/^s[0-9a-f]{8}$/', $saved[2]['key']);
    }

    // ---- lowering the retention ----------------------------------------------------------------------------------------------------

    /** ★ lowering the order retention asks for a confirmation that states the right count, and does nothing without it */
    public function test_lowering_the_retention_asks_for_confirmation_with_the_count_and_saves_nothing_without_it(): void
    {
        $this->actingAsStaff('Administrator');
        $this->insertOrders([
            ['email' => 'a@example.com', 'status' => 'delivered', 'placed_at' => '2020-03-01 10:00:00'],
            ['email' => 'b@example.com', 'status' => 'cancelled', 'placed_at' => '2020-06-01 10:00:00'],
            ['email' => 'c@example.com', 'status' => 'refunded', 'placed_at' => '2019-01-01 10:00:00'],
            ['email' => 'd@example.com', 'status' => 'placed', 'placed_at' => '2020-03-01 10:00:00'],   // not finished: never anonymised
            ['email' => 'e@example.com', 'status' => 'delivered', 'placed_at' => '2023-03-01 10:00:00'], // newer than the new cutoff
            ['email' => 'f@example.com', 'status' => 'delivered', 'placed_at' => '2010-03-01 10:00:00', 'anonymised_at' => '2030-01-01 00:00:00'], // already anonymised (finished)
        ]);
        $this->travelTo(now()->setDate(2026, 11, 24));

        $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => 5]);
        $this->mountSave($page);

        $this->assertNotSame([], $page->instance()->mountedActions, 'a confirmation is open');
        $this->assertSame(__('privacy_admin.settings.confirm_text', ['count' => 3]), (new \ReflectionMethod($page->instance(), 'confirmText'))->invoke($page->instance()), 'the right count: finished, not anonymised, older than the new cutoff');
        $this->assertSame(10, $this->settings()->orderRetentionYears(), 'nothing is saved until the person confirms');

        // cancelled: still nothing
        $page->call('unmountAction');
        $this->assertSame(10, $this->settings()->orderRetentionYears());

        // confirmed
        $this->save(Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => 5]));
        $this->assertSame(5, $this->settings()->orderRetentionYears());
    }

    public function test_raising_or_keeping_the_retention_asks_nothing(): void
    {
        $this->actingAsStaff('Administrator');

        foreach ([10, 15] as $years) {
            $page = Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => $years]);
            $this->mountSave($page);

            $this->assertSame([], $page->instance()->mountedActions, 'no confirmation for '.$years);
            $this->assertSame($years, $this->settings()->orderRetentionYears());
        }
    }

    public function test_the_count_uses_the_cutoff_of_the_anonymiser_for_the_new_value(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 24));
        $retention = app(OrderRetention::class);

        $this->assertSame(
            $retention->cutoffForYears(5)->format('c'),
            (clone $retention)->cutoffForYears(5)->format('c'),
        );
        $this->settings()->saveOrderRetentionYears(5);
        $this->assertSame($retention->cutoff()->format('c'), $retention->cutoffForYears(5)->format('c'), 'cutoff() is cutoffForYears() of the saved value: ONE place for the date rule');
        $this->assertSame(2021, (int) $retention->cutoff()->setTimezone(app(\App\Settings\StoreTimezone::class)->zone())->format('Y'));
    }

    // ---- the commands read these values ---------------------------------------------------------------------------------------------

    public function test_the_anonymiser_and_the_mail_log_prune_use_exactly_the_saved_values(): void
    {
        $this->actingAsStaff('Administrator');
        $this->save(Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => 5, 'mail_log_days' => 90]));

        // the anonymiser's cutoff
        $this->artisan('privacy:anonymise-orders', ['--dry-run' => true])->expectsOutputToContain('(retention 5 year(s))')->assertSuccessful();

        // the mail log prune
        foreach ([200, 100, 10] as $days) {
            DB::table('mail_log')->insert([
                'template_key' => 'order.confirmation', 'idempotency_key' => 'k'.$days, 'category' => 'transactional', 'to_email' => 'x@example.com', 'locale' => 'en',
                'status' => 'queued', 'queued_at' => now()->subDays($days), 'created_at' => now()->subDays($days), 'updated_at' => now(),
            ]);
        }

        $this->artisan('mail:prune-log')->expectsOutputToContain('Deleted 2 mail log row(s) older than 90 day(s).')->assertSuccessful();
        $this->assertSame(1, DB::table('mail_log')->count());

        // change it again: the next run uses the new value
        $this->save(Livewire::test(PrivacySettingsPage::class)->fillForm(['retention_years' => 20, 'mail_log_days' => 365]));
        $this->artisan('privacy:anonymise-orders', ['--dry-run' => true])->expectsOutputToContain('(retention 20 year(s))')->assertSuccessful();
    }
}
