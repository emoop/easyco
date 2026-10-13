<?php

namespace Tests\Feature\Storefront;

use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B: the checkout script on a page rendered with EVERYTHING on (the newsletter, the call box, a terms link, a
 * payment method of an extension with its own button label), driven in the fake DOM (tests/Feature/Storefront/Js/checkout-flow.mjs).
 * The page is rendered with the region rule "DE required". No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutChoicesFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        $settings = app(SiteSettingsRepository::class);
        $settings->set('site.locale', 'en');
        $settings->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, '1');
        $settings->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $settings->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/terms');
        $settings->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-10-14');
        $settings->set('checkout.region_countries', '{"DE":"required"}');

        // A TEST-ONLY extension: a method with its own order-button label, sorted first so it is the preselected one.
        app(PaymentMethodRegistry::class)->register(new PaymentMethodDescriptor(
            code: 'test_card', label: fn (string $locale): string => 'Test card', placeOrderLabel: fn (string $locale): string => 'Place order and pay by card', sortOrder: 1,
        ));
        $this->app->bind('payment.adapter.test_card', CashOnDeliveryPaymentMethodAdapter::class);
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'country_select_change_requotes', 'choices_in_payload', 'terms_unticked_blocks_the_submit', 'terms_version_changed_locks_the_order',
            'payment_label_follows_the_chosen_method', 'summary_top_is_measured',
        ];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckch').'.html';
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
