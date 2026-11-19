<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ACC-3b-2.1: a storefront page never carries Livewire's auto-injected assets (`<!-- Livewire Styles --><style>`, livewire.min.js). Livewire keeps «a component was rendered»
 * in statics (SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest): in production the process ends with the request, in a test run it outlives the test, so an admin
 * Livewire test earlier in the same PHPUnit process (CI shards) put the assets into the next test's storefront page. tests/TestCase.php resets it with Livewire's own
 * `flush-state` (LivewireManager::flushState()) before every test.
 */
class NoLivewireAssetsOnStorefrontTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return ['home' => ['/'], 'login' => ['/account/login'], 'register' => ['/account/register'], 'cart' => ['/cart'], 'checkout' => ['/checkout']];
    }

    private function assertNoLivewire(string $html, string $where): void
    {
        $this->assertStringNotContainsString('Livewire Styles', $html, $where);
        $this->assertStringNotContainsString('livewire.min.js', $html, $where);
        $this->assertStringNotContainsString('livewire', strtolower((string) preg_replace('#<meta[^>]*>#i', '', $html)), $where);
        $this->assertDoesNotMatchRegularExpression('#\swire:[a-z]#i', $html, $where.': no wire: attribute');
        $this->assertDoesNotMatchRegularExpression('#<style\b#i', $html, $where.': no <style>');
    }

    // ---- the proof: the leak exists, and the base's reset removes it -----------------------------------------------------------------

    /** the first half of a pair: a Livewire page rendered in THIS test leaves the state set for the next one */
    public function test_a_01_a_rendered_livewire_page_leaves_the_state_set(): void
    {
        $this->get('/admin/login')->assertOk();   // a real Livewire (Filament) page

        $this->assertTrue(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest);
    }

    /** the second half: the NEXT test of the same process starts clean, because the test base reset the state */
    #[DataProvider('pages')]
    public function test_a_02_the_next_test_of_the_same_process_gets_clean_storefront_pages(string $path): void
    {
        $this->assertFalse(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest, 'the base reset it');

        $this->assertNoLivewire((string) $this->get($path)->assertOk()->getContent(), $path);
    }

    // ---- production: a storefront request never renders a Livewire component, so nothing is ever injected ----------------------------

    #[DataProvider('pages')]
    public function test_a_storefront_request_never_renders_a_livewire_component(string $path): void
    {
        $this->get($path)->assertOk();

        $this->assertFalse(SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest, "{$path}: no Livewire component in the request lifecycle, so Livewire has nothing to inject");
    }
}
