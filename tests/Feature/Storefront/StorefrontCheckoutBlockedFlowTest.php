<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B (cod-protection-design.md section 4): the checkout script when the API answers
 * `payment_method_blocked` to a real attempt (there is no pre-check). Driven in the fake DOM (tests/Feature/Storefront/Js/checkout-flow.mjs):
 *
 *  - another method is left: the blockable one is marked unavailable and unchosen, the customer is asked to choose another, nothing is resent;
 *  - no other method: the order button is locked and the owner's sentence is shown («Свържете се посредством методите на контакт …»).
 *
 * The page never says "blocked" and never which identifier matched. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutBlockedFlowTest extends TestCase
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

    private function run_scenario(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckbl').'.html';
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

    public function test_with_another_method_the_blockable_one_is_marked_unavailable_and_the_customer_chooses(): void
    {
        $this->run_scenario('blocked_with_another_method');
    }

    public function test_with_no_other_method_the_button_is_locked_and_the_owners_sentence_is_shown(): void
    {
        $only = new PaymentMethodRegistry();
        $only->register(new PaymentMethodDescriptor(
            code: 'cash_on_delivery', label: fn (string $locale): string => 'Наложен платеж', sortOrder: 10, blockable: true,
        ));
        $this->app->instance(PaymentMethodRegistry::class, $only);

        $this->run_scenario('blocked_without_another_method');
    }

    public function test_the_page_marks_only_the_blockable_method(): void
    {
        $html = $this->get('/checkout')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="payment_method" value="cash_on_delivery"[^>]*data-blockable="1"/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="payment_method" value="bank_transfer"[^>]*data-blockable/', $html);
    }

    public function test_the_two_sentences_exist_in_both_languages_and_the_owners_is_exact(): void
    {
        $this->assertSame('Свържете се посредством методите на контакт за възможността да поръчате от сайта', __('storefront.js_checkout.ck_contact_shop', [], 'bg'));
        $this->assertSame('Този начин на плащане не е наличен за тази поръчка. Моля, изберете друг.', __('storefront.js_checkout.ck_payment_unavailable', [], 'bg'));
        $this->assertNotSame('storefront.js_checkout.ck_contact_shop', __('storefront.js_checkout.ck_contact_shop', [], 'en'));
        $this->assertNotSame('storefront.js_checkout.ck_payment_unavailable', __('storefront.js_checkout.ck_payment_unavailable', [], 'en'));
    }
}
