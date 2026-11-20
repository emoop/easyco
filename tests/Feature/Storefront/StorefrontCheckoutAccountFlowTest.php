<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * ACC-3c: the checkout script for a signed-in customer, driven in the fake DOM (tests/Feature/Storefront/Js/checkout-flow.mjs): who is signed in is
 * learned from the API after load (the page is the same for everyone), «Поръчвате като», the saved-address rows (default preselected, the first three,
 * «Още адреси», «Промени», «Нов адрес»), a saved address sent as its id only while unchanged, the pick-up points of the same courier and kind, the
 * optional recipient of the delivery mails, and an ended session. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutAccountFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
    }

    /** @return array<string, array{string}> */
    public static function scenarios(): array
    {
        $names = [
            'guest_sees_the_login_hint_and_keeps_the_email_field',
            'acct_default_address_is_preselected_and_goes_out_as_its_id',
            'acct_more_addresses_reveals_the_rest',
            'acct_switching_to_the_second_address_fills_and_requotes',
            'acct_change_sends_the_full_fields_and_never_the_id',
            'acct_editing_the_name_or_phone_of_a_chosen_address_sends_the_full_fields',
            'acct_new_address_for_another_person_with_a_recipient_email',
            'acct_office_offers_only_saved_points_of_the_same_courier_and_kind',
            'acct_office_without_a_saved_point_is_the_usual_single_field',
            'acct_an_ended_session_keeps_the_form_and_asks_for_the_email',
            'acct_a_401_or_419_is_the_same_expired_session',
            'acct_a_422_on_a_hidden_field_switches_to_edit_and_shows_the_error',
            'acct_a_kind_without_saved_points_does_not_lose_the_saved_row_for_good_without_a_saved_point',
            'acct_logout_posts_and_reloads_the_page',
        ];

        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_scenario_holds(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckac').'.html';
        file_put_contents($file, $this->get('/checkout')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/checkout-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame("OK {$scenario}", trim(implode("\n", $output)));
    }
}
