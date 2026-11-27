<?php

namespace Tests\Feature\Storefront;

use App\Settings\CheckoutRegionPolicy;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * COUR-2a.5: the place that is ALREADY in the checkout's place field (a saved address, the browser's autofill, a restored page) is resolved once, by the
 * same rule for every trigger, when the office / locker list is needed; the postcode is required for a delivery to an address. Driven in the fake DOM
 * (tests/Feature/Storefront/Js/checkout-flow.mjs). The fake DOM cannot see CSS or real events: tests/Browser/checkout-pickup.spec.mjs is the proof of those.
 * No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutResolveFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        app(SiteSettingsRepository::class)->set(CheckoutRegionPolicy::COUNTRIES_KEY, '{"DE":"required","FR":"hidden"}');
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'picker_resolve_a_prefilled_place_by_postcode_and_name',
            'picker_resolve_one_match_fills_an_empty_postcode',
            'picker_resolve_an_ambiguous_place_is_not_guessed',
            'picker_resolve_the_region_decides_when_there_is_no_postcode',
            'picker_resolve_a_place_that_is_not_found_shows_the_hint',
            'picker_resolve_follows_change_pageshow_and_edits',
            'picker_resolve_an_office_of_a_resolved_place_can_be_ordered',
            'postcode_is_required_for_an_address_only',
            'postcode_a_server_error_stands_under_the_field',
            'acct_resolve_the_address_book_without_a_saved_point_follows_the_chosen_address',
            'acct_resolve_a_saved_office_keeps_the_place_on_the_page_until_it_is_changed',
            'acct_resolve_typing_over_the_place_of_a_saved_office_leaves_it',
            'acct_resolve_a_saved_street_without_a_postcode_is_opened_and_asks_for_it_without_a_postcode',
            // COUR-2a.6
            'picker_resolve_nokind_says_which_kind_the_courier_has_there',
            'picker_resolve_nopoints_says_the_courier_has_nothing_in_the_place',
            'picker_resolve_a_typed_office_is_sent_under_the_courier_code',
            'place_key_and_confident_match',
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

        $file = tempnam(sys_get_temp_dir(), 'ckrs').'.html';
        file_put_contents($file, $this->get('/checkout')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/checkout-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('OK '.$scenario, implode("\n", $output));
    }

    /** COUR-2a.6: the script's place key and the server's name key (BgPostcodes::normalise) are ONE key: the same texts through both. */
    public function test_the_script_place_key_and_the_servers_name_key_agree(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $cases = [
            'Стара Загора', 'стара загора', 'Стара  Загора', "Стара\u{00A0}Загора", ' Стара Загора ', 'гр. Стара Загора', 'гр.Стара Загора', "гр.\u{00A0}Стара Загора", "\u{00A0}гр. Стара Загора", 'гр Стара Загора',
            "гр\u{00A0}Стара Загора", 'с. Дълго поле', 'село Дълго поле', 'град Велико Търново', 'Цар-Петрово', 'Цар - Петрово', 'Цар Петрово', 'София', 'Мария Луиза', 'Софи',
            'София-град', 'Бания', 'гр. София', 'Стария', 'Щип', 'ЩИП', 'Юндола', 'Ямбол', 'Harmanli', 'Sofia', 'Стара З', '  ', 'гр.', 'с.', "\u{2009}Стара\u{2009}Загора", 'Горна Оряховица', 'Ѝ', '6000',
        ];
        $file = tempnam(sys_get_temp_dir(), 'ckpk');
        $page = tempnam(sys_get_temp_dir(), 'ckpg').'.html';
        file_put_contents($file, json_encode(['cases' => $cases, 'keys' => array_map(static fn (string $text): string => \App\Support\BgPostcodes::normalise($text), $cases)], JSON_UNESCAPED_UNICODE));
        file_put_contents($page, $this->get('/checkout')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/checkout-flow.mjs').' '.escapeshellarg($page).' place_key_agrees_with_the_server '.escapeshellarg($file).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
            @unlink($page);
        }

        $this->assertSame(0, $code, implode("\n", $output));
    }
}
