<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\PageDesign\PageDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * CD-1a: the «Каталог» zone of the Design screen — it is in the list, opens at today's values, «Приложи» writes the draft, «Публикувай» makes it live (and forgets the draft), «Начални настройки»
 * loads the defaults into the working copy, a value outside a closed list is refused under its field, the schematic shows what the working copy says, and the authorisation is the screen's.
 */
class DesignCatalogZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

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

        return Livewire::test(DesignScreen::class)->call('selectZone', 'catalog');
    }

    /** @param  array<string, string>  $values */
    private function fill(Testable $screen, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $screen->set('props.'.$key, $value);
        }

        return $screen;
    }

    private function published(): ?string
    {
        return $this->settings()->get(AppearanceSettings::CATALOG_DESIGN_KEY);
    }

    private function draft(): ?string
    {
        return $this->settings()->get(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY);
    }

    public function test_the_zone_is_in_the_list_and_opens_at_todays_values_with_a_hint_under_each_control(): void
    {
        $screen = $this->screen();
        $html = $screen->html();

        $this->assertArrayHasKey('catalog', DesignScreen::ZONES);
        $this->assertStringContainsString('>Catalog</button>', $html);
        $screen->assertSet('zone', 'catalog')->assertSet('props.cols', '4')->assertSet('props.cols_phone', '2')->assertSet('props.per_page', '24')
            ->assertActionVisible('reset')->assertActionVisible('discard')->assertActionVisible('publish')->assertActionDisabled('publish');

        foreach (['Columns on desktop', 'Columns on phone', 'Products per page', 'Default 4.', 'Default 2.', 'Default 24.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringNotContainsString('side panel', strtolower(strip_tags(str_replace('The layout and the side panel come with the filters.', '', $html))), 'no layout / side-panel control yet (they come with the filters)');
        $this->assertSame(['cols', 'cols_phone', 'per_page'], array_keys(array_intersect_key($screen->get('props'), array_flip(['cols', 'cols_phone', 'per_page', 'layout', 'side']))));
    }

    public function test_the_schematic_shows_the_working_copy(): void
    {
        $screen = $this->screen();
        $html = $screen->html();

        $this->assertStringContainsString('repeat(4, 1fr)', $html);
        $this->assertStringContainsString('repeat(2, 1fr)', $html);
        $this->assertSame(8 + 4, substr_count($html, 'height:1.5rem;background:#e5e7eb'), 'two rows of 4 and two rows of 2');

        $html = $this->fill($screen, ['cols' => '5', 'cols_phone' => '1', 'per_page' => '48'])->call('apply')->html();

        $this->assertStringContainsString('repeat(5, 1fr)', $html);
        $this->assertStringContainsString('repeat(1, 1fr)', $html);
        $this->assertSame(10 + 2, substr_count($html, 'height:1.5rem;background:#e5e7eb'));
        $this->assertStringContainsString('Products per page: 48', $html);
    }

    public function test_apply_writes_the_draft_only_and_publish_makes_it_live_and_forgets_the_draft(): void
    {
        $screen = $this->fill($this->screen(), ['cols' => '5', 'cols_phone' => '1', 'per_page' => '48'])->call('apply')->assertHasNoErrors();

        $this->assertNull($this->published(), 'the shop is not touched by «Apply»');
        $this->assertSame(['cols' => '5', 'cols_phone' => '1', 'per_page' => '48'], json_decode((string) $this->draft(), true));
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY));

        $screen->call('publish')->assertHasNoErrors();

        $this->assertSame(['cols' => '5', 'cols_phone' => '1', 'per_page' => '48'], json_decode((string) $this->published(), true));
        $this->assertNull($this->draft(), 'the draft is forgotten after publishing');
        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::COMPILED_KEY), true);
        $css = (string) Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css');
        $this->assertStringContainsString('--sf-cat-cols: 5;', $css);
        $this->assertStringContainsString('--sf-cat-cols-phone: 1;', $css);
    }

    public function test_only_what_differs_is_stored_and_a_return_to_the_defaults_forgets_the_key(): void
    {
        $screen = $this->fill($this->screen(), ['per_page' => '36'])->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertSame(['cols' => '4', 'cols_phone' => '2', 'per_page' => '36'], json_decode((string) $this->published(), true), 'the section is stored as a whole while it differs');
        $this->assertNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'the per-page size is not a stylesheet variable');

        // CD-1a.2: 20 is a member of the list: stored, read back as 20 by the storefront reader and shown by the select after «Apply»
        $screen->set('props.per_page', '20')->call('apply')->call('publish')->assertHasNoErrors()->assertSet('props.per_page', '20');
        $this->assertSame('20', json_decode((string) $this->published(), true)['per_page']);
        $this->app->forgetScopedInstances();
        $this->assertSame(20, app(\App\Storefront\Appearance\StorefrontAppearance::class)->catalogPerPage());

        $screen->set('props.per_page', '24')->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertNull($this->published());
        $this->assertNull($this->draft());
    }

    public function test_the_reset_puts_the_defaults_into_the_working_copy_only(): void
    {
        $screen = $this->fill($this->screen(), ['cols' => '5', 'per_page' => '60'])->call('apply')->call('publish')->assertHasNoErrors();
        $live = $this->published();

        $screen->call('resetToDefault')->assertSet('props.cols', '4')->assertSet('props.per_page', '24');

        $this->assertSame($live, $this->published(), 'nothing reaches the shop until «Publish»');
        $this->assertSame(['cols' => '4', 'cols_phone' => '2', 'per_page' => '24'], json_decode((string) $this->draft(), true));

        $screen->call('publish')->assertHasNoErrors();
        $this->assertNull($this->published());
    }

    public function test_discard_forgets_the_draft(): void
    {
        $screen = $this->fill($this->screen(), ['cols' => '3'])->call('apply');
        $this->assertNotNull($this->draft());

        $screen->call('discard');

        $this->assertNull($this->draft());
        $this->assertNull($this->published());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badInputs(): array
    {
        return [
            'columns 6' => ['cols', '6'], 'columns 2' => ['cols', '2'], 'columns declaration' => ['cols', '4;color:red'], 'columns 600 digits' => ['cols', str_repeat('9', 600)],
            'phone 3' => ['cols_phone', '3'], 'phone 0' => ['cols_phone', '0'], 'phone declaration' => ['cols_phone', '1;color:red'],
            'per page 10' => ['per_page', '10'], 'per page 100' => ['per_page', '100'], 'per page text' => ['per_page', 'all'], 'per page declaration' => ['per_page', '24;color:red'],
            'markup' => ['per_page', '"><b>x</b>'], 'empty' => ['cols', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function test_a_value_outside_a_closed_list_is_refused_under_its_field_and_nothing_is_stored(string $field, string $value): void
    {
        $this->fill($this->screen(), [$field => $value])->call('apply')->assertHasErrors(['props.'.$field]);

        $this->assertNull($this->draft());
        $this->assertNull($this->published());
    }

    public function test_a_role_without_the_permission_cannot_open_or_save_the_zone(): void
    {
        $this->actingAsStaff('Product Entry');
        $this->get(DesignScreen::getUrl().'?zone=catalog')->assertForbidden();

        foreach (['apply', 'publish', 'discard', 'resetToDefault'] as $call) {
            $screen = $this->fill($this->screen(), ['cols' => '5']);

            $this->app->forgetScopedInstances();
            $this->actingAsStaff('Product Entry');
            $screen->call($call)->assertForbidden();
        }

        $this->assertNull($this->draft());
        $this->assertNull($this->published());
    }

    public function test_the_zone_is_one_of_the_sections_the_compiler_and_the_store_know(): void
    {
        $this->assertContains(PageDesign::CATALOG, PageDesign::SECTIONS);
        $this->assertSame(AppearanceSettings::CATALOG_DESIGN_KEY, PageDesign::KEYS[PageDesign::CATALOG]);
        $this->assertSame(AppearanceSettings::CATALOG_DESIGN_DRAFT_KEY, PageDesign::DRAFT_KEYS[PageDesign::CATALOG]);
    }
}
