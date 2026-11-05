<?php

namespace Tests\Feature\Admin\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P3: the «Съдържание» zone of the «Дизайн» screen: it opens at «no template» (every page as it always was), a kind select, the default template of the kind, a search that opens a page's OWN template, the list of
 * the pages with their own settings and «Върни към общия» (which removes only that page's template); «Приложи» writes a draft, «Публикувай» the set and its compiled copy, a bad value is refused and nothing is
 * written.
 */
class DesignContentZoneTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeBrand(1, 'Acme', 'acme');
    }

    private function screen(string $zone = 'content'): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    private function stored(string $key): array
    {
        return (array) json_decode((string) app(SiteSettingsRepository::class)->get($key), true);
    }

    private function block(string $marker): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => $marker, 'body' => "<p>{$marker}</p>"]);
    }

    /** the panel's fields of a template with the cloud on, a top block and nothing below */
    private function form(bool $cloud = true, ?int $top = null, string $bottomType = '', array $carousel = []): array
    {
        return ['cloud' => $cloud, 'top' => $top, 'bottom_type' => $bottomType, 'bottom_block' => null, 'carousel' => $carousel + ['kind' => 'products', 'source' => 'current', 'entity' => null, 'count' => '12', 'sort' => 'newest', 'arrows' => true, 'label' => '', 'block_items' => []]];
    }

    public function test_the_zone_opens_with_no_template_at_all(): void
    {
        $screen = $this->screen();

        $screen->assertSet('zone', 'content');
        $this->assertSame('categories', $screen->get('props.kind'));

        foreach (['categories', 'brands', 'seasons', 'tags'] as $kind) {
            $this->assertFalse($screen->get("props.{$kind}.default.cloud"), $kind);
            $this->assertNull($screen->get("props.{$kind}.default.top"), $kind);
            $this->assertSame('', $screen->get("props.{$kind}.default.bottom_type"), $kind);
            $this->assertSame([], (array) $screen->get("props.{$kind}.own"), $kind);
        }

        $this->assertStringContainsString('Default template', $screen->html());
        $this->assertStringContainsString('Pages with their own settings', $screen->html());
        $this->assertStringContainsString('Link cloud', $screen->html());
    }

    /** ★ «Приложи» writes the draft only; «Публикувай» writes the set and makes it live */
    public function test_apply_writes_the_draft_and_publish_makes_the_default_live(): void
    {
        $top = $this->block('TOPTEXT');
        $screen = $this->screen();

        $screen->set('props.categories.default', $this->form(true, $top->id, 'carousel'))->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::CONTENT_DRAFT_KEY);
        $this->assertTrue($draft['categories']['default']['cloud']);
        $this->assertSame($top->id, $draft['categories']['default']['top']);
        $this->assertSame('current', $draft['categories']['default']['bottom']['settings']['source']);

        $screen->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->assertSame($top->id, $published['categories']['default']['top']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_DRAFT_KEY), 'the draft is forgotten');
        $this->assertFalse($this->screen()->get('dirty'));

        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
        $this->app->forgetScopedInstances();
        $this->get('/product-category/clothing/dresses')->assertOk()->assertSee('TOPTEXT', false);
    }

    /** ★ the search adds a page's own template; «Върни към общия» removes only that one */
    public function test_a_page_gets_its_own_template_and_goes_back_to_the_default(): void
    {
        $screen = $this->screen();
        $screen->set('props.categories.default', $this->form(true));

        // the search field: choosing a page opens ITS template (a blank one) in the list, and clears the field
        $screen->set('props.categories.add', 2);
        $own = array_values((array) $screen->get('props.categories.own'));

        $this->assertCount(1, $own);
        $this->assertSame(2, $own[0]['subject']);
        $this->assertSame('Clothing › Dresses', $own[0]['label']);
        $this->assertNull($screen->get('props.categories.add'));

        // choosing it again does not add a second one; a page that does not exist adds nothing
        $screen->set('props.categories.add', 2)->set('props.categories.add', 999);
        $this->assertCount(1, (array) $screen->get('props.categories.own'));

        $key = array_key_first((array) $screen->get('props.categories.own'));
        $screen->set("props.categories.own.{$key}.cloud", false)->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->assertTrue($published['categories']['default']['cloud']);
        $this->assertSame(['2'], array_map('strval', array_keys($published['categories']['own'])));
        $this->assertFalse($published['categories']['own']['2']['cloud'], 'an own template is WHOLE: it says no cloud although the default does');

        // «Върни към общия»: the item goes, the default stays
        $again = $this->screen();
        $this->assertCount(1, (array) $again->get('props.categories.own'));
        $again->set('props.categories.own', [])->call('apply')->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->assertSame([], (array) $published['categories']['own']);
        $this->assertTrue($published['categories']['default']['cloud']);
    }

    /** ★ a blank set publishes as «nothing»: the compiled copy and the stored set are removed */
    public function test_clearing_everything_removes_the_published_set(): void
    {
        $screen = $this->screen();
        $screen->set('props.brands.default', $this->form(true))->call('apply')->call('publish')->assertHasNoErrors();
        $this->assertNotNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY));

        $this->screen()->set('props.brands.default', $this->form(false))->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY));
        $this->assertStringNotContainsString('"content"', (string) app(SiteSettingsRepository::class)->get(AppearanceSettings::DESIGN_COMPILED_KEY));
    }

    /** ★ a bad value is refused and nothing is written (a block that does not exist is a stored id, but a non-number is refused) */
    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        foreach ([
            ['props.categories.default', $this->form(true, null, 'carousel', ['source' => 'sale'])],
            ['props.categories.default', ['cloud' => true, 'top' => 'abc'] + $this->form()],
            ['props.categories.default', ['bottom_type' => 'html_block', 'bottom_block' => null] + $this->form()],
            ['props.brands.own', [['subject' => 999, 'label' => 'x'] + $this->form()]],
        ] as [$path, $value]) {
            $screen = $this->screen()->set($path, $value)->call('apply');

            $screen->assertHasErrors(['props.kind']);
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_DRAFT_KEY), $path.' '.json_encode($value));
        }
    }

    public function test_the_carousel_here_offers_this_page_and_only_the_kinds_that_need_no_picture(): void
    {
        $html = $this->screen()->set('props.categories.default', $this->form(true, null, 'carousel'))->html();

        $this->assertStringContainsString('This page', $html);
        $this->assertStringNotContainsString('Brand logos', $html);
        $this->assertStringNotContainsString('>Images<', $html);
    }

    /** the admin's list drops a ghost: a page that no longer exists is not listed */
    public function test_a_deleted_page_is_not_listed_and_the_draft_survives_it(): void
    {
        $this->makeCategory(3, 'Gone', 'gone');
        [$clean] = ContentSet::validate(['categories' => ['default' => null, 'own' => [3 => ['cloud' => true, 'top' => null, 'bottom' => null]]]], ContentStore::existing());
        ContentStore::publish($clean);
        DB::table('catalog_categories')->where('id', 3)->delete();

        $this->assertSame([], (array) $this->screen()->get('props.categories.own'));
    }

    public function test_the_zone_is_for_staff_who_may_design(): void
    {
        $this->actingAsStaff('Product Entry');

        Livewire::test(DesignScreen::class)->assertForbidden();
    }

    /** ★ P3.1: the zone opens with the default look (cloud «Като сайта», title «С линии»); the two shop-wide choices are saved with the set, published and read back; a bad value is refused */
    public function test_the_shop_wide_cloud_shape_and_title_style_are_saved_and_checked(): void
    {
        $screen = $this->screen();
        $this->assertSame('site', $screen->get('props.cloud_shape'));
        $this->assertSame('lines', $screen->get('props.title_style'));
        $this->assertStringContainsString('Cloud shape', $screen->html());
        $this->assertStringContainsString('Title style', $screen->html());

        $screen->set('props.cloud_shape', 'pill')->set('props.title_style', 'plain')->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $published = $this->stored(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->assertSame(['cloud_shape' => 'pill', 'title_style' => 'plain'], $published['style']);

        $again = $this->screen();
        $this->assertSame('pill', $again->get('props.cloud_shape'));
        $this->assertSame('plain', $again->get('props.title_style'));

        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
        $this->app->forgetScopedInstances();
        $this->get('/product-category/clothing/dresses')->assertOk()->assertSee('<h1 class="sf-title">Dresses</h1>', false);

        // a value that is not in its list is refused and nothing is written
        $before = app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->screen()->set('props.cloud_shape', 'triangle')->call('publish');
        $this->assertSame($before, app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY));

        // «Начални настройки»-like: the default look and no template is the same as nothing published
        $this->assertTrue(ContentSet::isBlank(ContentSet::blank()));
        $this->assertFalse(ContentSet::isBlank(ContentSet::read(['style' => ['cloud_shape' => 'pill']])));
    }

    /** ★ P3.1: «Покажи заглавието» and «Заглавие над долния блок» are fields of every template, saved and refused when too long */
    public function test_a_template_has_the_title_switch_and_the_heading_above_the_bottom_block(): void
    {
        $block = $this->block('LOWER');
        $screen = $this->screen();
        $this->assertTrue($screen->get('props.categories.default.title'));
        $this->assertSame('', $screen->get('props.categories.default.bottom_heading'));
        $this->assertStringContainsString('Show the title', $screen->html());

        $form = ['title' => false, 'bottom_heading' => 'More to read', 'bottom_block' => $block->id] + $this->form(false, null, 'html_block');
        $screen->set('props.categories.default', $form)->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $default = $this->stored(AppearanceSettings::CONTENT_PUBLISHED_KEY)['categories']['default'];
        $this->assertFalse($default['title']);
        $this->assertSame('More to read', $default['bottom_heading']);

        $this->makeProduct(1, ['name' => 'P1', 'slug' => 'p1', 'categories' => [2], 'price' => '10.00', 'stock' => 3]);
        $this->app->forgetScopedInstances();
        $html = $this->get('/product-category/clothing/dresses')->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="sf-title sf-sr">Dresses</h1>', $html);
        $this->assertStringContainsString('More to read', $html);

        // too long: refused, nothing changes
        $before = app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $this->screen()->set('props.categories.default', ['bottom_heading' => str_repeat('x', 81)] + $form)->call('publish');
        $this->assertSame($before, app(SiteSettingsRepository::class)->get(AppearanceSettings::CONTENT_PUBLISHED_KEY));
    }
}
