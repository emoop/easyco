<?php

namespace Tests\Feature\Storefront;

use App\Content\Menus\MenuException;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuSeeder;
use App\Content\Menus\MenuTree;
use App\Content\Menus\MenuValidator;
use App\Content\Menus\MenuWriter;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Menu\CompiledMenus;
use App\Storefront\Menu\MenuCompileException;
use App\Storefront\Menu\MenuCompiler;
use App\Storefront\Menu\MenuRecompiler;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage D3: menus as a tool. The structure rules and the link / label rules of the validator, the compiler (what is skipped, what is resolved), the compiled value
 * read back with validation, the rebuild triggers (and their debounce), the markup of the bar / dropdown / mega panel / drawer / footer and the query numbers.
 */
class MenusTest extends TestCase
{
    use AssertsSafeHtml;
    use BuildsMenus;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.name' => 'Raf Shop']);
        app()->setLocale('en');
        Storage::fake('public');
        CompiledMenus::forgetWarnings();
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeCategory(4, 'Empty', 'empty');
        $this->makeProduct(1, ['slug' => 'dress', 'categories' => [1, 2], 'price' => '10.00', 'stock' => 3, 'name' => 'Summer dress']);
        $this->makeProduct(2, ['slug' => 'boot', 'categories' => [3], 'price' => '20.00', 'stock' => 3, 'name' => 'Boot']);
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /** @return list<array<string, mixed>> the stored compiled items of one menu */
    private function compiledMenu(string $key): array
    {
        return json_decode((string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY), true)['menus'][$key] ?? [];
    }

    private function fresh(): void
    {
        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
    }

    private function page(string $slug, string $title, string $status = 'published'): PageModel
    {
        return app(PageWriter::class)->create(['title' => $title, 'slug' => $slug, 'body' => '<p>Text</p>', 'status' => $status]);
    }

    private function link(string $label, string $url, array $extra = []): array
    {
        return $this->menuItem('link', ['label' => $label, 'url' => $url] + $extra);
    }

    // ---- structure and field rules --------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string, mixed>>, 1: string}> [tree, the path that must carry the message] */
    public static function brokenTrees(): array
    {
        $link = static fn (string $label = 'A', array $extra = []): array => ['type' => 'link', 'label' => $label, 'url' => '/a'] + $extra;
        $cat = static fn (array $extra = []): array => ['type' => 'category', 'target_id' => 1] + $extra;
        $dropdown = static fn (array $children): array => $link('D', ['submenu' => 'dropdown', 'children' => $children]);
        $mega = static fn (array $children): array => $link('M', ['submenu' => 'mega', 'children' => $children]);
        $column = static fn (array $children): array => ['type' => 'column', 'children' => $children];
        $image = static fn (): array => ['type' => 'image', 'media_asset_id' => 1];

        return [
            'depth four' => [[$dropdown([$link('B', ['children' => [$link('C', ['children' => [$link('E')]])]])])], 'items.0.children.0.children.0.children'],
            'a submenu on a child' => [[$dropdown([$link('B', ['submenu' => 'dropdown'])])], 'items.0.children.0.submenu'],
            'a link directly under a mega item' => [[$mega([$link('B')])], 'items.0.children.0.type'],
            'an image under a dropdown' => [[$dropdown([$image()])], 'items.0.children.0.type'],
            'a column under a dropdown' => [[$dropdown([$column([])])], 'items.0.children.0.type'],
            'a column at the top' => [[$column([])], 'items.0.type'],
            'three images in a panel' => [[$mega([$column([$image(), $image()]), $column([$image()])])], 'items.0.children'],
            'thirteen top-level items' => [array_map(fn (int $i): array => $link("L{$i}"), range(1, 13)), 'items'],
            'sixteen items in a column' => [[$mega([$column(array_map(fn (int $i): array => $link("L{$i}"), range(1, 16)))])], 'items.0.children.0.children'],
            'children without a submenu' => [[$link('A', ['children' => [$link('B')]])], 'items.0.children'],
            'items inside a column item' => [[$mega([$column([$link('B', ['children' => [$link('C')]])])])], 'items.0.children.0.children.0.children'],
            'with_children on a link' => [[$link('A', ['with_children' => true])], 'items.0.with_children'],
            'with_children on a mega item' => [[$cat(['submenu' => 'mega', 'with_children' => true])], 'items.0.with_children'],
            'an unknown type' => [[['type' => 'script']], 'items.0.type'],
            'an unknown key' => [[$link('A', ['onclick' => 'x'])], 'items.0.onclick'],
            'an unknown submenu mode' => [[$link('A', ['submenu' => 'giant'])], 'items.0.submenu'],
            'a category without a target' => [[['type' => 'category']], 'items.0.target_id'],
            'a link without a label' => [[['type' => 'link', 'url' => '/a']], 'items.0.label'],
            'a link without an address' => [[['type' => 'link', 'label' => 'A']], 'items.0.url'],
            'a javascript: link' => [[array_merge($link('A'), ['url' => 'javascript:alert(1)'])], 'items.0.url'],
            'a protocol-relative link' => [[['type' => 'link', 'label' => 'A', 'url' => '//evil.test/x']], 'items.0.url'],
            'a plain http link' => [[['type' => 'link', 'label' => 'A', 'url' => 'http://example.com']], 'items.0.url'],
            'a data: link' => [[['type' => 'link', 'label' => 'A', 'url' => 'data:text/html,x']], 'items.0.url'],
            'a url on a category' => [[$cat(['url' => '/x'])], 'items.0.url'],
            'a label that is too long' => [[$link(str_repeat('a', 121))], 'items.0.label'],
            'a label with a control character' => [[$link("Hi\nthere")], 'items.0.label'],
            'a label with a bidirectional override' => [[$link("Hi\u{202E}x")], 'items.0.label'],
            'a limit over 50' => [[$cat(['children_limit' => 51])], 'items.0.children_limit'],
            'a limit of 0' => [[$cat(['children_limit' => 0])], 'items.0.children_limit'],
            'a caption that is too long' => [[$mega([$column([['type' => 'image', 'media_asset_id' => 1, 'caption' => str_repeat('c', 81)]])])], 'items.0.children.0.children.0.caption'],
            'an image without an asset' => [[$mega([$column([['type' => 'image']])])], 'items.0.children.0.children.0.media_asset_id'],
        ];
    }

    /** @param  list<array<string, mixed>>  $tree */
    #[DataProvider('brokenTrees')]
    public function test_a_broken_tree_is_refused_with_a_message_on_the_right_path(array $tree, string $path): void
    {
        $validation = MenuValidator::validate($tree);

        $this->assertFalse($validation->passes());
        $this->assertArrayHasKey($path, $validation->errors, 'errors: '.json_encode($validation->errors));
        $this->assertStringNotContainsString('menus.errors.', $validation->errors[$path], 'a sentence, not a key');
    }

    public function test_the_writer_refuses_a_broken_tree_and_changes_nothing(): void
    {
        $this->saveMenu('main', [$this->link('Keep', '/keep')]);
        $before = $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY);

        try {
            $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
            app(MenuWriter::class)->save($menu, 'Main', [$this->link('Bad', 'javascript:alert(1)')]);
            $this->fail('the tree should have been refused');
        } catch (MenuException $exception) {
            $this->assertArrayHasKey('items.0.url', $exception->errors);
        }

        $this->assertSame(['Keep'], array_column(MenuTree::forMenu(MenuModel::query()->where('key', 'main')->value('id')), 'label'));
        $this->assertSame($before, $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
    }

    public function test_the_writer_refuses_a_target_that_does_not_exist(): void
    {
        $menu = MenuModel::query()->where('key', 'main')->firstOrFail();

        foreach ([['category', 99], ['page', 99]] as [$type, $id]) {
            try {
                app(MenuWriter::class)->save($menu, 'Main', [$this->menuItem($type, ['target_id' => $id])]);
                $this->fail('refused');
            } catch (MenuException $exception) {
                $this->assertArrayHasKey('items.0.target_id', $exception->errors);
            }
        }

        try {
            app(MenuWriter::class)->save($menu, 'Main', [$this->menuItem('link', ['label' => 'M', 'url' => '/m', 'submenu' => 'mega', 'children' => [$this->menuItem('column', ['children' => [$this->menuItem('image', ['media_asset_id' => 99])]])]])]);
            $this->fail('refused');
        } catch (MenuException $exception) {
            $this->assertArrayHasKey('items.0.children.0.children.0.media_asset_id', $exception->errors);
        }
    }

    public function test_a_tree_that_follows_the_rules_is_saved_and_read_back_in_its_order(): void
    {
        $this->makeMenuImage(900);
        $this->saveMenu('main', [
            $this->link('First', '/first'),
            $this->menuItem('category', ['target_id' => 1, 'submenu' => 'mega', 'children' => [
                $this->menuItem('column', ['label' => 'Women', 'children' => [$this->link('Sale', '/sale'), $this->menuItem('image', ['media_asset_id' => 900, 'caption' => 'New'])]]),
            ]]),
            $this->link('Last', '/last', ['new_tab' => true]),
        ]);

        $tree = MenuTree::forMenu((int) MenuModel::query()->where('key', 'main')->value('id'));

        $this->assertSame(['link', 'category', 'link'], array_column($tree, 'type'));
        $this->assertSame(['First', null, 'Last'], array_column($tree, 'label'));
        $this->assertSame('column', $tree[1]['children'][0]['type']);
        $this->assertSame(['link', 'image'], array_column($tree[1]['children'][0]['children'], 'type'));
        $this->assertTrue($tree[2]['new_tab']);
    }

    // ---- the compiler ------------------------------------------------------------------------------------------------------------------------

    public function test_a_category_that_is_not_shown_is_skipped_with_its_whole_subtree(): void
    {
        $this->saveMenu('main', [
            $this->menuItem('category', ['target_id' => 4, 'submenu' => 'dropdown', 'children' => [$this->link('Inside', '/inside')]]),
            $this->menuItem('category', ['target_id' => 3]),
        ]);

        $compiled = $this->compiledMenu('main');

        $this->assertSame(['Shoes'], array_column($compiled, 'label'), 'Empty has no visible product: it and its child link are gone');
        $this->assertStringNotContainsString('Inside', (string) $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
    }

    public function test_an_unpublished_page_is_skipped_and_a_published_one_is_resolved(): void
    {
        $published = $this->page('terms', 'Terms');
        $draft = $this->page('draft-page', 'Draft page', 'draft');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $published->id]), $this->menuItem('page', ['target_id' => $draft->id])]);

        $compiled = $this->compiledMenu('footer');

        $this->assertSame(['Terms'], array_column($compiled, 'label'));
        $this->assertSame('/info/terms', $compiled[0]['url']);
    }

    public function test_a_deleted_target_is_skipped_not_a_broken_menu(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3]), $this->link('Stay', '/stay')]);
        DB::table('catalog_product_categories')->where('category_id', 3)->delete();
        DB::table('catalog_categories')->where('id', 3)->delete();

        app(MenuRecompiler::class)->now();

        $this->assertSame(['Stay'], array_column($this->compiledMenu('main'), 'label'));
    }

    public function test_an_empty_label_shows_the_targets_own_name_and_a_label_wins(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1]), $this->menuItem('category', ['target_id' => 3, 'label' => 'Footwear'])]);

        $this->assertSame(['Clothing', 'Footwear'], array_column($this->compiledMenu('main'), 'label'));
    }

    public function test_with_children_lists_only_shown_subcategories_cut_at_the_limit_with_see_all(): void
    {
        foreach ([5, 6, 7] as $id) {
            $this->makeCategory($id, "Sub {$id}", "sub-{$id}", 1);
            $this->makeProduct(10 + $id, ['slug' => "p-{$id}", 'categories' => [$id], 'price' => '5.00', 'stock' => 1]);
        }

        $this->makeCategory(8, 'Hidden sub', 'hidden-sub', 1);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'with_children' => true, 'children_limit' => 3])]);

        $children = $this->compiledMenu('main')[0]['children'];

        $this->assertSame(['Dresses', 'Sub 5', 'Sub 6', null], array_column($children, 'label'), 'Hidden sub (no product) is not listed; 4 are shown, 3 are kept, then See all');
        $this->assertTrue($children[3]['see_all']);
        $this->assertSame('/product-category/clothing', $children[3]['url']);
        $this->assertSame('dropdown', $this->compiledMenu('main')[0]['submenu']);
    }

    public function test_a_dropdown_with_nothing_left_becomes_a_plain_link(): void
    {
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3, 'submenu' => 'dropdown', 'with_children' => true])]);

        $item = $this->compiledMenu('main')[0];

        $this->assertSame('none', $item['submenu']);
        $this->assertSame([], $item['children']);
    }

    public function test_an_image_is_resolved_only_from_a_ready_asset(): void
    {
        $this->makeMenuImage(901);
        $this->makeMenuImage(902, 'processing');
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'M', 'url' => '/m', 'submenu' => 'mega', 'children' => [
            $this->menuItem('column', ['label' => 'Col', 'children' => [
                $this->link('L', '/l'),
                $this->menuItem('image', ['media_asset_id' => 901, 'url' => '/promo', 'caption' => 'Promo']),
                $this->menuItem('image', ['media_asset_id' => 902]),
            ]]),
        ]])]);

        $column = $this->compiledMenu('main')[0]['children'][0];
        $images = array_values(array_filter($column['children'], fn (array $c): bool => $c['type'] === 'image'));

        $this->assertCount(1, $images, 'the processing asset is dropped');
        $this->assertSame([900, 675], [$images[0]['width'], $images[0]['height']]);
        $this->assertCount(2, $images[0]['srcset']);
        $this->assertSame('Promo', $images[0]['alt'], 'no alt: the caption');
        $this->assertSame('/promo', $images[0]['url']);

        // The asset is deleted: the next rebuild drops the image too.
        MediaAssetModel::query()->whereKey(901)->delete();
        app(MenuRecompiler::class)->now();
        $this->assertSame(['link'], array_column($this->compiledMenu('main')[0]['children'][0]['children'], 'type'));
    }

    public function test_the_alt_text_falls_back_to_the_shop_name(): void
    {
        $this->makeMenuImage(903);
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'M', 'url' => '/m', 'submenu' => 'mega', 'children' => [
            $this->menuItem('column', ['children' => [$this->menuItem('image', ['media_asset_id' => 903])]]),
        ]])]);

        $this->assertSame('Raf Shop', $this->compiledMenu('main')[0]['children'][0]['children'][0]['alt']);
    }

    public function test_a_compiled_value_over_the_size_limit_refuses_the_save_and_keeps_the_old_one(): void
    {
        $this->saveMenu('main', [$this->link('Old', '/old')]);
        $before = $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY);
        $this->app->bind(MenuCompiler::class, fn () => new class($this->settings(), app(\App\Storefront\Url\StorefrontUrls::class), app(\App\Storefront\Visibility\StorefrontVisibility::class), app(\App\Storefront\Reader\ImageSetBuilder::class)) extends MenuCompiler {
            public const MAX_BYTES = 40;
        });
        $this->app->forgetInstance(MenuRecompiler::class);

        try {
            $menu = MenuModel::query()->where('key', 'main')->firstOrFail();
            app(MenuWriter::class)->save($menu, 'Main', [$this->link('A much longer item than the new tiny limit allows', '/long')]);
            $this->fail('the save should have been refused');
        } catch (MenuCompileException $exception) {
            $this->assertStringContainsString('too large', $exception->getMessage());
        }

        $this->assertSame($before, $this->settings()->get(AppearanceSettings::MENUS_COMPILED_KEY));
        $this->assertSame(['Old'], array_column(MenuTree::forMenu((int) MenuModel::query()->where('key', 'main')->value('id')), 'label'), 'the rows were rolled back too');
    }

    // ---- reading the compiled value ------------------------------------------------------------------------------------------------------------

    private function putCompiled(array $menus): void
    {
        $this->settings()->set(AppearanceSettings::MENUS_COMPILED_KEY, json_encode(['menus' => $menus]));
        $this->fresh();
    }

    private function goodLink(string $label = 'Good', string $url = '/good'): array
    {
        return ['type' => 'link', 'label' => $label, 'url' => $url, 'new_tab' => false, 'submenu' => 'none', 'children' => []];
    }

    public function test_a_hand_edited_menu_with_a_javascript_link_renders_nothing_and_logs_once(): void
    {
        Log::spy();
        $this->putCompiled(['main' => [$this->goodLink('Fine'), $this->goodLink('Evil', 'javascript:alert(1)')], 'footer' => [$this->goodLink('Footer ok', '/f')]]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->fresh();
        $this->get('/')->assertOk();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Evil', $html);
        $this->assertStringNotContainsString('Fine', $html, 'the whole menu is dropped, not just the bad item');
        $this->assertStringContainsString('Footer ok', $html, 'another menu is not affected');
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('invalidCompiledNodes')]
    public function test_other_hand_edited_shapes_render_an_empty_menu(array $node): void
    {
        $this->putCompiled(['main' => [$this->goodLink('Fine'), $node]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('sf-bar-link', $html);
        $this->assertStringNotContainsString('Fine', $html);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidCompiledNodes(): array
    {
        $link = ['type' => 'link', 'label' => 'X', 'url' => '/x', 'new_tab' => false, 'submenu' => 'none', 'children' => []];

        return [
            'an unknown type' => [['type' => 'iframe', 'src' => 'x']],
            'a protocol-relative url' => [['url' => '//evil.test'] + $link],
            'a label that is not a string' => [['label' => ['x']] + $link],
            'a label with a control character' => [['label' => "A\u{0007}B"] + $link],
            'an unknown submenu mode' => [['submenu' => 'huge'] + $link],
            'new_tab that is not a boolean' => [['new_tab' => 'yes'] + $link],
            'an image with a data: source' => [['type' => 'column', 'label' => null, 'children' => [['type' => 'image', 'label' => null, 'url' => null, 'new_tab' => false, 'alt' => 'a', 'src' => 'data:image/svg+xml,x', 'srcset' => [], 'width' => 1, 'height' => 1]]]],
            'a tree that is far too deep' => [['children' => [['children' => [['children' => [['children' => [['children' => [['children' => [$link]] + $link]] + $link]] + $link]] + $link]] + $link]] + $link],
        ];
    }

    public function test_a_valid_stored_menu_is_rebuilt_from_known_keys_only(): void
    {
        $good = $this->goodLink('Fine');
        $good['onclick'] = 'x';
        $read = CompiledMenus::read(['main' => [$good]]);

        $this->assertSame(['type', 'label', 'url', 'new_tab', 'submenu', 'children'], array_keys($read['main'][0]), 'nothing unvalidated is passed on');
    }

    // ---- the rebuild triggers ----------------------------------------------------------------------------------------------------------------------

    public function test_unpublishing_and_publishing_a_page_changes_the_footer_without_a_manual_save(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $this->assertStringContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent());

        app(PageWriter::class)->update($page, ['status' => 'draft']);
        $this->fresh();
        $this->assertStringNotContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent(), 'unpublished: gone');

        app(PageWriter::class)->update($page->refresh(), ['status' => 'published']);
        $this->fresh();
        $this->assertStringContainsString('href="/info/terms"', $this->get('/')->assertOk()->getContent(), 'published again: back');
    }

    public function test_a_renamed_page_and_a_new_slug_are_followed(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);

        app(PageWriter::class)->update($page, ['title' => 'Terms of sale', 'slug' => 'terms-of-sale']);
        $this->fresh();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="/info/terms-of-sale">Terms of sale</a>', $html);
    }

    public function test_hiding_the_last_product_of_a_category_removes_it_from_the_menu_without_a_manual_save(): void
    {
        $this->mainMenuOfTopCategories();
        $this->assertStringContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent());

        ProductModel::query()->findOrFail(2)->update(['status' => 'draft']);
        $this->fresh();

        $this->assertStringNotContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent(), 'the category is not shown any more, so neither is its menu item');

        ProductModel::query()->findOrFail(2)->update(['status' => 'active']);
        $this->fresh();
        $this->assertStringContainsString('href="/product-category/shoes"', $this->get('/')->assertOk()->getContent());
    }

    public function test_renaming_and_creating_a_category_follow_the_menu(): void
    {
        $this->mainMenuOfTopCategories();

        CategoryModel::query()->findOrFail(3)->update(['name' => 'Boots']);
        $this->fresh();
        $this->assertStringContainsString('>Boots</a>', $this->get('/')->assertOk()->getContent());

        CategoryModel::query()->findOrFail(3)->delete();
        $this->fresh();
        $this->assertStringNotContainsString('>Boots</a>', $this->get('/')->assertOk()->getContent());
    }

    public function test_a_picture_that_becomes_ready_is_picked_up(): void
    {
        $this->makeMenuImage(910, 'processing');
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'M', 'url' => '/m', 'submenu' => 'mega', 'children' => [
            $this->menuItem('column', ['label' => 'Col', 'children' => [$this->link('L', '/l'), $this->menuItem('image', ['media_asset_id' => 910])]]),
        ]])]);
        $this->assertStringNotContainsString('sf-mega-img', $this->get('/')->assertOk()->getContent());

        MediaAssetModel::query()->findOrFail(910)->update([
            'processing_status' => 'ready',
            'variants' => [['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 82, 'path' => 'm/910-medium.webp']],
        ]);
        $this->fresh();

        $this->assertStringContainsString('class="sf-mega-img"', $this->get('/')->assertOk()->getContent());
    }

    public function test_several_changes_in_one_request_rebuild_once(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $page = $this->page('a-page', 'A page');
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id])]);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3])]);
        $runs = $recompiler->runs;

        app(PageWriter::class)->update($page, ['title' => 'Another title']);
        CategoryModel::query()->findOrFail(3)->update(['name' => 'Footwear']);
        CategoryModel::query()->findOrFail(1)->update(['name' => 'Wear']);
        $this->assertTrue($recompiler->hasPending());
        $this->assertSame($runs, $recompiler->runs, 'nothing ran yet: the rebuild waits for the end of the request');

        $recompiler->flush();
        $recompiler->flush();

        $this->assertSame($runs + 1, $recompiler->runs);
        $this->assertFalse($recompiler->hasPending());
    }

    public function test_a_shop_whose_menus_hold_no_category_pays_nothing_when_a_product_changes(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $this->saveMenu('main', [$this->link('Only a link', '/x')]);

        ProductModel::query()->findOrFail(1)->update(['status' => 'draft']);
        CategoryModel::query()->findOrFail(3)->update(['name' => 'Renamed']);

        $this->assertFalse($recompiler->hasPending(), 'no menu item is a category: nothing could change');
    }

    public function test_a_product_save_that_changes_nothing_visible_asks_for_no_rebuild(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $this->saveMenu('main', [$this->menuItem('category', ['target_id' => 3])]);

        ProductModel::query()->findOrFail(1)->update(['name' => 'Renamed dress']);

        $this->assertFalse($recompiler->hasPending());
    }

    public function test_the_pending_rebuild_runs_after_a_queue_job(): void
    {
        $recompiler = app(MenuRecompiler::class);
        $recompiler->request();
        $runs = $recompiler->runs;

        event(new JobProcessed('sync', \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)));

        $this->assertSame($runs + 1, $recompiler->runs);
    }

    // ---- query numbers ---------------------------------------------------------------------------------------------------------------------------------

    /** @return array{0: list<string>, 1: list<string>} the queries of a COLD page and the ones that touch menus */
    private function queries(string $url): array
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $route->flushController();
        }

        Cache::forget(StorefrontAppearance::CACHE_KEY);
        $this->app->forgetScopedInstances();
        $all = [];
        DB::listen(function ($query) use (&$all): void {
            $all[] = $query->sql.' | '.json_encode($query->bindings);
        });

        $this->get($url)->assertOk();

        return [$all, array_values(array_filter($all, fn (string $q): bool => str_contains($q, 'menus') || str_contains($q, 'menu_items')))];
    }

    public function test_menus_cost_no_query_of_their_own_on_any_storefront_page(): void
    {
        $this->page('terms', 'Terms');
        app(MenuRecompiler::class)->flush(); // the page above was made by a writer: its rebuild belongs to the end of THAT request
        $url = fn (string $path): string => $path;
        $pages = ['/', '/product-category/clothing', '/product/dress', '/cart', '/checkout', '/info/terms'];

        $empty = [];

        foreach ($pages as $path) {
            $empty[$path] = $this->queries($url($path))[0];
        }


        $this->mainMenuOfTopCategories();
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => PageModel::query()->value('id')])]);

        foreach ($pages as $path) {
            [$queries, $menuQueries] = $this->queries($url($path));

            $this->assertSame(count($empty[$path]), count($queries), "{$path}: a full menu does not add a query. Extra: ".json_encode(array_values(array_diff($queries, $empty[$path]))));
            $this->assertCount(1, $menuQueries, "{$path}: exactly the one appearance read names menus.compiled, no table of the menus is read");
            $this->assertStringContainsString('site_settings', $menuQueries[0]);
        }
    }

    // ---- the markup --------------------------------------------------------------------------------------------------------------------------------------

    private function megaMenu(): void
    {
        $this->makeMenuImage(920);
        $this->saveMenu('main', [
            $this->link('Plain', '/plain'),
            $this->menuItem('category', ['target_id' => 1, 'submenu' => 'dropdown', 'with_children' => true]),
            $this->menuItem('category', ['target_id' => 3, 'submenu' => 'mega', 'children' => [
                $this->menuItem('column', ['label' => 'Boots', 'children' => [$this->link('Winter', '/winter'), $this->link('Summer', '/summer', ['new_tab' => true])]]),
                $this->menuItem('column', ['label' => 'Pictures', 'children' => [$this->menuItem('image', ['media_asset_id' => 920, 'url' => '/promo', 'caption' => 'Promo', 'image_alt' => 'A boot'])]]),
            ]]),
        ]);
    }

    public function test_a_dropdown_item_is_a_link_plus_a_separate_disclosure_button(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();

        // The parent is a real link...
        $this->assertMatchesRegularExpression('#<a class="sf-bar-link" href="/product-category/clothing">Clothing</a>#', $html);
        // ...and a separate button owns the open / close state.
        $this->assertMatchesRegularExpression('#<button class="sf-bar-chevron" type="button" aria-expanded="false" aria-controls="(sf-sub-[a-z0-9-]+)" data-sf-sub-toggle>#', $html);
        preg_match('#<button class="sf-bar-chevron" type="button" aria-expanded="false" aria-controls="([^"]+)"#', $html, $m);
        $this->assertSame(1, substr_count($html, 'id="'.$m[1].'"'), 'aria-controls points at an existing panel');
        $this->assertSame(0, preg_match('#<button[^>]*>\s*Clothing#', $html), 'the label is never the button');
        $this->assertStringContainsString('class="sf-sub sf-sub-dropdown"', $html);
        $this->assertStringContainsString('<a class="sf-menu-link" href="/product-category/clothing/dresses">Dresses</a>', $html);
    }

    public function test_the_mega_panel_is_in_the_page_from_the_start_with_lazy_dimensioned_images(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('class="sf-sub sf-sub-mega"', $html);
        $this->assertSame(2, substr_count($html, 'class="sf-mega-col"'));
        $this->assertStringContainsString('<p class="sf-mega-heading">Boots</p>', $html);
        $this->assertStringContainsString('<a class="sf-menu-link" href="/summer" target="_blank" rel="noopener">Summer</a>', $html);
        $this->assertMatchesRegularExpression('#<img class="sf-mega-img" alt="A boot" width="900" height="675" sizes="[^"]+" loading="lazy" decoding="async" data-sf-src="[^"]*m/920-medium\.webp" data-sf-srcset="[^"]*400w, [^"]*900w">#', $html);
        $this->assertStringNotContainsString(' src="/storage/m/920-medium.webp" data-sf-src', $html, 'the live image has no src until the panel first opens');
        $this->assertMatchesRegularExpression('#<noscript><img class="sf-mega-img"[^>]* src="[^"]*m/920-medium\.webp"[^>]*loading="lazy"></noscript>#', $html, 'without the script a plain lazy image');
        $this->assertStringContainsString('<figcaption class="sf-mega-caption">Promo</figcaption>', $html);
        $this->assertNoActiveContent(preg_replace('#<form[ >].*?</form>#s', '', $html), 'mega menu');
    }

    public function test_the_drawer_shows_the_same_tree_as_an_accordion_without_pictures(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'id="sf-drawer"'));

        $this->assertStringContainsString('class="sf-acc"', $drawer);
        $this->assertStringContainsString('<a class="sf-acc-link" href="/product-category/clothing">Clothing</a>', $drawer);
        $this->assertStringContainsString('<p class="sf-mega-heading">Boots</p>', $drawer);
        $this->assertStringContainsString('>Winter</a>', $drawer);
        $this->assertStringNotContainsString('sf-mega-img', $drawer);
        $this->assertStringNotContainsString('Promo', $drawer);
    }

    public function test_without_the_script_nothing_is_hidden_and_the_list_wraps_in_its_own_column(): void
    {
        $this->megaMenu();
        $html = $this->get('/')->assertOk()->getContent();
        $css = (string) file_get_contents(resource_path('css/storefront.css'));

        $this->assertSame(3, preg_match_all('/<li class="sf-bar-item[^"]*" data-sf-bar-item>/', $html), 'every top-level item is in the page');
        $this->assertSame(1, preg_match_all('/<li[^>]* hidden>/', $html), 'only the (empty) More item is hidden');
        $this->assertMatchesRegularExpression('/\.sf-bar \{[^}]*flex-wrap: wrap;/', $css, 'wraps by default (no script)');
        $this->assertMatchesRegularExpression('/html\.js \.sf-bar \{\s*flex-wrap: nowrap;/', $css, 'one row only once the script can fit it');
        $this->assertMatchesRegularExpression('/\.sf-hdr-menu-links \{\s*flex: 1 1 auto;\s*min-width: 0;/', $css, 'the bar lives inside its own column');
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);', $css, 'the centre column stays auto, the sides shrink (S3-V2)');
        $this->assertMatchesRegularExpression('/html:not\(\.js\) \.sf-bar-item:hover > \.sf-sub/', $css, 'a panel opens on hover and focus without the script');
    }

    public function test_the_footer_renders_the_footer_menu_only(): void
    {
        $page = $this->page('terms', 'Terms');
        $this->page('privacy', 'Privacy');
        DB::table('pages')->update(['in_footer' => 1]);
        $this->saveMenu('footer', [$this->menuItem('page', ['target_id' => $page->id]), $this->link('Our blog', 'https://blog.example.com/', ['new_tab' => true]), $this->menuItem('category', ['target_id' => 3])]);

        $html = $this->get('/')->assertOk()->getContent();
        $footer = substr($html, (int) strpos($html, '<footer'));

        $this->assertStringContainsString('<li><a href="/info/terms">Terms</a></li>', $footer);
        $this->assertStringContainsString('<li><a href="https://blog.example.com/" target="_blank" rel="noopener">Our blog</a></li>', $footer);
        $this->assertStringContainsString('<li><a href="/product-category/shoes">Shoes</a></li>', $footer);
        $this->assertStringNotContainsString('Privacy', $footer, 'the old in_footer flag no longer puts a page in the footer');
    }

    public function test_the_seed_puts_the_old_footer_pages_in_the_footer_menu_in_the_same_order_and_is_idempotent(): void
    {
        $first = $this->page('terms', 'Terms');
        $second = $this->page('privacy', 'Privacy');
        $third = $this->page('delivery', 'Delivery');
        $this->page('hidden', 'Not in footer');
        DB::table('pages')->where('id', $first->id)->update(['in_footer' => 1, 'footer_order' => 2]);
        DB::table('pages')->where('id', $second->id)->update(['in_footer' => 1, 'footer_order' => 1]);
        DB::table('pages')->where('id', $third->id)->update(['in_footer' => 1, 'footer_order' => 1]);
        MenuModel::query()->whereIn('key', ['main', 'footer'])->delete();

        MenuSeeder::run();
        app(MenuRecompiler::class)->now();

        $this->assertSame(['Delivery', 'Privacy', 'Terms'], array_column($this->compiledMenu('footer'), 'label'), 'footer_order, then title: the order the footer had');

        // The merchant changes the main menu; a second run leaves everything alone.
        $this->saveMenu('main', [$this->link('Mine', '/mine')]);
        MenuSeeder::run();

        $this->assertSame(['Mine'], array_column($this->compiledMenu('main'), 'label'), 'the own main menu of the merchant is left alone');
        $this->assertSame(3, DB::table('menu_items')->where('menu_id', MenuModel::query()->where('key', 'footer')->value('id'))->count(), 'and the footer got nothing twice');
        $this->assertSame(2, MenuModel::query()->count());
    }

    public function test_the_seed_builds_main_from_the_top_level_categories_in_their_order(): void
    {
        MenuModel::query()->whereIn('key', ['main', 'footer'])->delete();

        MenuSeeder::run();
        app(MenuRecompiler::class)->now();

        $main = $this->compiledMenu('main');

        $this->assertSame(['Clothing', 'Shoes'], array_column($main, 'label'), 'Empty has no product: not shown, but still in the menu for the merchant to see');
        $this->assertSame(['dropdown', 'none'], array_column($main, 'submenu'));
        $this->assertSame(['Dresses'], array_column($main[0]['children'], 'label'));
        $this->assertSame(3, DB::table('menu_items')->where('menu_id', MenuModel::query()->where('key', 'main')->value('id'))->count());
    }

    public function test_the_hamburger_is_named_menu_not_categories(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-sf-menu-toggle[^>]*>.*?<span class="sf-sr-only">Menu</span>#s', $html);
        $this->assertStringNotContainsString('sf-sr-only">Categories<', $html);
    }

    public function test_the_header_can_still_use_the_category_tree_as_its_source(): void
    {
        $layout = \App\Storefront\Header\HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'] = [['type' => 'menu', 'settings' => ['source' => 'categories', 'style' => 'links']]];
        app(\App\Storefront\Header\HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Categories"', $html);
        $this->assertStringContainsString('<a class="sf-bar-link" href="/product-category/clothing">Clothing</a>', $html);
        $this->assertStringContainsString('<a class="sf-bar-link" href="/product-category/shoes">Shoes</a>', $html);
        $this->assertStringNotContainsString('>Empty</a>', $html, 'only the shown categories');
    }

    public function test_an_unknown_menu_key_in_the_header_prints_nothing(): void
    {
        $layout = \App\Storefront\Header\HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'] = [['type' => 'menu', 'settings' => ['source' => 'no-such-menu', 'style' => 'links']]];
        app(\App\Storefront\Header\HeaderCompiler::class)->publish($layout, $this->settings());
        $this->fresh();

        $this->assertStringNotContainsString('data-sf-menubar', $this->get('/')->assertOk()->getContent());
    }
}
