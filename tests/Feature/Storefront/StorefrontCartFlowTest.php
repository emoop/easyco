<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * Stage S3-V: the cart page's script on the REAL rendered /cart shell, driven in the fake DOM (tests/Feature/Storefront/Js/cart-flow.mjs): the empty
 * state without a request (R1), the summary and the enabled checkout link, the stepper through ONE handler, minus at 1, the in-flight lock and the
 * retry. No browser, no new dependency; needs `node` (skipped without).
 */
class StorefrontCartFlowTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->seedPricing();
        $category = $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [$category], 'price' => '10.00', 'stock' => 3]);
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'empty_state_without_cookie', 'cart_with_lines_has_the_summary_the_stepper_and_an_enabled_checkout_link', 'a_promotion_shows_its_discount_row',
            'plus_sends_one_patch', 'typing_a_quantity_sends_one_patch', 'minus_at_one_sends_nothing', 'minus_above_one_steps_down',
            'an_invalid_quantity_is_put_back', 'in_flight_disables_the_controls', 'a_failure_offers_a_retry',
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

        $file = tempnam(sys_get_temp_dir(), 'ckcart').'.html';
        file_put_contents($file, $this->get('/cart')->assertOk()->getContent());

        try {
            $output = [];
            exec('node '.escapeshellarg(__DIR__.'/Js/cart-flow.mjs').' '.escapeshellarg($file).' '.escapeshellarg($scenario).' 2>&1', $output, $code);
        } finally {
            @unlink($file);
        }

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame("OK {$scenario}", trim(implode("\n", $output)));
    }
}
