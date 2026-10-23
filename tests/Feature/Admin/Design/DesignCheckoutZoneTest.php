<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-2b, the «Количка и поръчка» zone: the simplified header of the cart and the checkout as a form zone with a draft («Приложи»), publish and discard, errors on the field, the preview frame and
 * the same permission rule as every other action of the screen.
 */
class DesignCheckoutZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private const INPUT = ['enabled' => true, 'logo_height_d' => '32', 'logo_height_m' => '24', 'back_label' => 'Keep shopping', 'back_url' => '/product-category/clothing', 'phone' => '+359 888 123 456', 'bg' => '#112233', 'fg' => '#ffffff'];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'checkout');
    }

    /** @param  array<string, mixed>  $values */
    private function fill(Testable $screen, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $screen->set('props.'.$key, $value);
        }

        return $screen;
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return json_decode((string) $this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY), true);
    }

    public function test_the_zone_starts_from_the_defaults_then_the_published_value_then_the_draft(): void
    {
        $this->assertSame(CheckoutHeaderCatalog::defaults(), $this->screen()->get('tree')['values']);

        $this->settings()->set(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY, json_encode(array_replace(CheckoutHeaderCatalog::defaults(), ['back_label' => 'Published'])));
        $this->assertSame('Published', $this->screen()->get('tree')['values']['back_label']);

        $this->settings()->set(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, json_encode(array_replace(CheckoutHeaderCatalog::defaults(), ['back_label' => 'Draft'])));
        $this->assertSame('Draft', $this->screen()->get('tree')['values']['back_label']);
    }

    public function test_it_is_a_form_zone_with_a_miniature_no_palette_and_the_cart_preview(): void
    {
        $html = $this->screen()->html();

        $this->assertStringContainsString('Cart preview', $html);
        $this->assertStringContainsString('ds-mini-cols', $html);
        $this->assertStringContainsString('Shown on the cart, the checkout and the order-received page', $html);
        $this->assertStringContainsString('Simplified header for the cart and checkout', $html);
        $this->assertStringNotContainsString('ds-palette', $html);
        $this->assertMatchesRegularExpression('#src="[^"]*/design-preview\?[^"]*zone=checkout_header#', html_entity_decode($html));
    }

    public function test_apply_writes_a_draft_and_reloads_the_preview_with_a_fresh_signed_url(): void
    {
        $screen = $this->screen();
        $first = $screen->get('previewUrl');

        $this->travel(2)->seconds();
        $this->fill($screen, self::INPUT)->call('apply')->assertHasNoErrors();

        $this->assertSame(['enabled' => true, 'logo_height_d' => 32, 'logo_height_m' => 24, 'back_label' => 'Keep shopping', 'back_url' => '/product-category/clothing', 'phone' => '+359 888 123 456', 'bg' => '#112233', 'fg' => '#ffffff'], $this->draft());
        $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY));

        $second = $screen->get('previewUrl');
        $this->assertNotSame($first, $second);
        $this->assertTrue(URL::hasValidSignature(Request::create($second)));
        $this->assertStringContainsString('zone=checkout_header', $second);
        $screen->assertSet('dirty', false);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInputs(): array
    {
        return [
            'an unsafe link' => [['back_url' => 'javascript:alert(1)'], 'back_url'],
            'a colour that is not hex' => [['bg' => 'red'], 'bg'],
            'a national phone number' => [['phone' => '0888 123 456'], 'phone'],
            'a logo that is too small' => [['logo_height_d' => '10'], 'logo_height_d'],
            'a phone logo that is too tall' => [['logo_height_m' => '65'], 'logo_height_m'],
            'a link text over 30 characters' => [['back_label' => str_repeat('l', 31)], 'back_label'],
        ];
    }

    /** @param  array<string, mixed>  $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function test_a_bad_value_is_refused_on_its_own_field_and_nothing_is_written(array $change, string $field): void
    {
        $screen = $this->fill($this->screen(), array_replace(self::INPUT, $change))->call('apply');

        $screen->assertHasErrors(['props.'.$field]);
        $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY));
        $screen->assertSet('selected', ['kind' => 'form', 'id' => 'checkout']);
    }

    public function test_publish_compiles_and_the_cart_and_the_checkout_show_it_then_discard_forgets_the_draft(): void
    {
        $screen = $this->fill($this->screen(), self::INPUT)->callAction('publish')->assertHasNoErrors();

        $document = json_decode((string) $this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY), true);
        $this->assertSame('Keep shopping', $document['zones']['checkout_header']['global']['back_label']);
        $this->assertSame($this->draft(), $document['zones']['checkout_header']['global']);

        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('data-sf-checkout-header', $this->get('/cart')->assertOk()->getContent());
        $this->assertStringContainsString('data-sf-checkout-header', $this->get('/checkout')->assertOk()->getContent());

        $screen->set('props.back_label', 'Only a draft')->callAction('apply');
        $this->assertSame('Only a draft', $this->draft()['back_label']);

        $screen->callAction('discard');
        $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY));
        $this->assertSame('Keep shopping', $screen->get('tree')['values']['back_label'], 'back to what is published');
    }

    public function test_the_actions_are_journalled_with_the_same_names_as_the_other_zones(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $screen = $this->screen();
        $screen->call('apply')->call('publish')->call('discard');

        $this->assertSame(['draft', 'published', 'discarded'], DB::table('activity_log')->where('entity_type', 'storefront_checkout_header')->orderBy('id')->pluck('field')->all());
    }

    public function test_every_action_refuses_a_user_without_the_permission(): void
    {
        foreach ([['apply'], ['publish'], ['discard']] as $call) {
            $screen = $this->screen();
            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call(...$call)->assertForbidden();
        }

        $this->assertNull($this->settings()->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::DESIGN_COMPILED_KEY));
    }
}
