<?php

namespace Tests\Feature\Storefront;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * C1 review: the checkout script's courier-then-type flow, run for real. The rendered /checkout page is parsed by a tiny fake
 * DOM (tests/Feature/Storefront/Js/checkout-flow.mjs) and the REAL script runs on it against a scripted API with two couriers
 * (one with a single delivery type) and one courier-less method. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutFlowTest extends TestCase
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

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = ['structure', 'courier_then_type', 'single_type_courier', 'courierless_method', 'requote_keeps_choice', 'requote_clears_vanished_choice', 'summary_toggle'];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckout').'.html';
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
