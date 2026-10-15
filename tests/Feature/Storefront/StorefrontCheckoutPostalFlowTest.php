<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage C1e, G4 (the one functional change of the visual pass): the postal code moved next to the place, driven in the fake DOM
 * (tests/Feature/Storefront/Js/checkout-flow.mjs). Hidden for an office or a locker and shown otherwise; the quote keeps sending it with
 * the place; a pick-up order never sends it; an address order does. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCheckoutPostalFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = ['postal_code_follows_the_kind', 'postal_code_is_sent_for_an_address', 'promo_input_has_the_floating_placeholder'];

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function test_the_script_flow(string $scenario): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node'));

        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        $file = tempnam(sys_get_temp_dir(), 'ckpo').'.html';
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
