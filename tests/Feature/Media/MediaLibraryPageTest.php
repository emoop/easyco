<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\MediaResource;
use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Content\HtmlBlocks\HtmlBlockModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-1a: the «Медия» page. Who may open it (content_manage OR product_manage, every action re-checked), the grid with its search, filters and sort, the details slide-over with the usage list,
 * the alt text that is cleaned and logged, and the pinned number of queries.
 */
class MediaLibraryPageTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
    }

    private function asset(array $override = []): MediaAssetModel
    {
        $uuid = uniqid();
        $createdAt = $override['created_at'] ?? null;
        unset($override['created_at']);

        $asset = MediaAssetModel::query()->create($override + [
            'type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready',
            'variants' => [['tier' => 'thumbnail', 'width' => 400, 'height' => 300, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-thumbnail.webp"], ['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-medium.webp"], ['tier' => 'large', 'width' => 1600, 'height' => 1200, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-large.webp"]],
            'original_name' => "photo-{$uuid}.jpg", 'mime' => 'image/jpeg', 'size_bytes' => 1000, 'width' => 40, 'height' => 30,
        ]);

        $createdAt !== null && DB::table('catalog_media')->where('id', $asset->id)->update(['created_at' => $createdAt]);

        return $asset->fresh();
    }

    private function product(string $name): ProductModel
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper(substr(md5($name), 0, 6)), 'slug-'.strtolower(substr(md5($name), 0, 6)));
        app(ProductRepository::class)->save($product);

        return ProductModel::query()->findOrFail($product->id());
    }

    private function names(\Livewire\Features\SupportTesting\Testable $component): array
    {
        return $component->instance()->getTableRecords()->pluck('original_name')->all();
    }

    /** The body of the details slide-over for one asset, rendered the way the modal does (the modal itself is a Livewire partial that the test helpers do not return). */
    private function detailsHtml(MediaAssetModel $asset): string
    {
        return view('filament.pages.media.details', MediaResource::detailsData($asset))->render();
    }

    // ---- access ------------------------------------------------------------------------------------------------------------------

    public function test_content_manage_and_product_manage_may_open_it_and_it_is_in_the_content_group(): void
    {
        foreach ([[Permission::CONTENT_MANAGE], [Permission::PRODUCT_MANAGE]] as $permissions) {
            $this->actingAsCustomRole($permissions);

            $this->get(MediaResource::getUrl('index'))->assertOk();
            $this->assertTrue(MediaResource::canAccess());
            $this->assertSame(\App\Filament\NavigationGroup::CONTENT, MediaResource::getNavigationGroup());
            $this->assertCount(1, MediaResource::getNavigationItems());
        }

        $this->actingAsStaff('Administrator');
        $this->get(MediaResource::getUrl('index'))->assertOk();
    }

    public function test_a_role_with_neither_permission_gets_a_403_and_no_navigation_item(): void
    {
        $this->asset();

        foreach ([fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]), fn () => $this->actingAsStaff('Product Entry')] as $actAs) {
            $actAs();
            if (MediaResource::canAccess()) {
                continue; // a seeded role that does hold one of the two permissions is not a case of this test
            }

            $this->get(MediaResource::getUrl('index'))->assertForbidden();
            Livewire::test(ListMedia::class)->assertForbidden();
            $this->assertFalse(MediaResource::canViewAny());
        }

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $this->assertFalse(MediaResource::canAccess());
        $this->get(MediaResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_direct_action_call_is_refused_when_the_permission_was_taken_away(): void
    {
        $asset = $this->asset();
        $this->actingAsCustomRole([Permission::CONTENT_MANAGE]);
        $component = Livewire::test(ListMedia::class)->mountTableAction('details', $asset);

        // the control: the same call by someone who may use the page saves
        $this->actingAsCustomRole([Permission::PRODUCT_MANAGE]);
        $component->setTableActionData(['alt_text' => 'control'])->callMountedTableAction();
        $this->assertSame('control', $asset->fresh()->alt_text);

        // the permission is gone by the time the call arrives
        $component = Livewire::test(ListMedia::class)->mountTableAction('details', $asset);
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        try {
            $component->setTableActionData(['alt_text' => 'sneaky'])->callMountedTableAction();
        } catch (\Throwable) {
            // the refusal is a 403 answer with no Livewire snapshot, which the test helper reports as an exception: what counts is that nothing was saved
        }

        $this->assertSame('control', $asset->fresh()->alt_text, 'nothing is saved');
    }

    public function test_the_saved_alt_text_helper_itself_refuses_without_the_permission(): void
    {
        $asset = $this->asset();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        try {
            MediaResource::saveAltText((int) $asset->id, 'sneaky');
            $this->fail('saved');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($asset->fresh()->alt_text);
    }

    // ---- the grid ----------------------------------------------------------------------------------------------------------------

    public function test_search_finds_a_file_by_its_original_name_and_by_its_alt_text(): void
    {
        $byName = $this->asset(['original_name' => 'summer-dress.jpg']);
        $byAlt = $this->asset(['original_name' => 'IMG_1.jpg', 'alt_text' => 'A red summer hat']);
        $other = $this->asset(['original_name' => 'winter-coat.jpg']);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ListMedia::class);
        $component->assertCanSeeTableRecords([$byName, $byAlt, $other]);

        $component->searchTable('summer')->assertCanSeeTableRecords([$byName, $byAlt])->assertCanNotSeeTableRecords([$other]);
        $component->searchTable('dress')->assertCanSeeTableRecords([$byName])->assertCanNotSeeTableRecords([$byAlt, $other]);
    }

    public function test_the_kind_filter(): void
    {
        $image = $this->asset();
        $video = $this->asset(['type' => 'video', 'variants' => null, 'mime' => 'video/mp4', 'width' => null, 'height' => null]);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMedia::class)
            ->filterTable('kind', 'video')->assertCanSeeTableRecords([$video])->assertCanNotSeeTableRecords([$image])
            ->filterTable('kind', 'image')->assertCanSeeTableRecords([$image])->assertCanNotSeeTableRecords([$video]);
    }

    public function test_the_use_filter_splits_used_and_unused_pictures(): void
    {
        $onProduct = $this->asset();
        $onBrand = $this->asset();
        $inBlock = $this->asset();
        $unused = $this->asset();
        ProductMediaModel::query()->create(['product_id' => $this->product('Рокля')->id, 'media_id' => $onProduct->id, 'sort_order' => 0, 'autoplay' => false]);
        BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $onBrand->id]);
        HtmlBlockModel::query()->create(['title' => 'Промо', 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $inBlock->id, 'alt' => '', 'url' => '']]]);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMedia::class)
            ->filterTable('use', 'unused')->assertCanSeeTableRecords([$unused])->assertCanNotSeeTableRecords([$onProduct, $onBrand, $inBlock])
            ->filterTable('use', 'used')->assertCanSeeTableRecords([$onProduct, $onBrand, $inBlock])->assertCanNotSeeTableRecords([$unused]);
    }

    public function test_the_state_filter_and_the_state_of_a_card_agree(): void
    {
        $ready = $this->asset();
        $processing = $this->asset(['processing_status' => 'pending', 'variants' => null]);
        $failed = $this->asset(['processing_status' => 'failed', 'variants' => null, 'processing_failure_reason' => 'decode error']);
        $missingMarked = $this->asset(['missing_at' => now()]);
        $missingNoVariants = $this->asset(['variants' => null]);
        $demoted = $this->asset(['path' => 'uploads/2026/10/abc-thumbnail.webp', 'variants' => []]);
        $video = $this->asset(['type' => 'video', 'variants' => null]);
        $this->actingAsStaff('Administrator');

        $this->assertSame(['ready', 'processing', 'failed', 'missing', 'missing', 'ready', 'video'], array_map(fn ($a) => MediaResource::stateOf($a), [$ready, $processing, $failed, $missingMarked, $missingNoVariants, $demoted, $video]));

        Livewire::test(ListMedia::class)
            ->filterTable('state', 'ready')->assertCanSeeTableRecords([$ready, $demoted, $video])->assertCanNotSeeTableRecords([$processing, $failed, $missingMarked, $missingNoVariants])
            ->filterTable('state', 'processing')->assertCanSeeTableRecords([$processing])->assertCanNotSeeTableRecords([$ready, $failed, $missingMarked])
            ->filterTable('state', 'failed')->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$ready, $processing])
            ->filterTable('state', 'missing')->assertCanSeeTableRecords([$missingMarked, $missingNoVariants])->assertCanNotSeeTableRecords([$ready, $processing, $failed, $demoted, $video]);
    }

    public function test_the_month_filter(): void
    {
        $october = $this->asset(['created_at' => '2026-10-15 10:00:00']);
        $september = $this->asset(['created_at' => '2026-09-30 23:59:00']);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListMedia::class)
            ->filterTable('month', ['month' => '2026-10'])->assertCanSeeTableRecords([$october])->assertCanNotSeeTableRecords([$september])
            ->filterTable('month', ['month' => '2026-09'])->assertCanSeeTableRecords([$september])->assertCanNotSeeTableRecords([$october])
            ->filterTable('month', ['month' => 'nonsense'])->assertCanSeeTableRecords([$october, $september]);
    }

    public function test_the_sort_options(): void
    {
        $old = $this->asset(['original_name' => 'b-old.jpg', 'size_bytes' => 9000, 'created_at' => '2026-01-01 10:00:00']);
        $new = $this->asset(['original_name' => 'c-new.jpg', 'size_bytes' => 100, 'created_at' => '2026-10-01 10:00:00']);
        $mid = $this->asset(['original_name' => 'a-mid.jpg', 'size_bytes' => 500, 'created_at' => '2026-05-01 10:00:00']);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ListMedia::class);
        $component = Livewire::test(ListMedia::class);
        $component->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);

        $component->filterTable('sort', 'largest')->assertCanSeeTableRecords([$old, $mid, $new], inOrder: true);
        $component->filterTable('sort', 'name')->assertCanSeeTableRecords([$mid, $old, $new], inOrder: true);
    }

    public function test_a_card_shows_the_thumbnail_the_name_the_size_and_the_usage_badge_and_escapes_the_name(): void
    {
        $used = $this->asset(['original_name' => '<script>alert(1)</script>.jpg', 'alt_text' => '"><img src=x onerror=1>']);
        $unused = $this->asset(['original_name' => 'plain.jpg']);
        ProductMediaModel::query()->create(['product_id' => $this->product('Рокля')->id, 'media_id' => $used->id, 'sort_order' => 0, 'autoplay' => false]);
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(ListMedia::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        $this->assertStringNotContainsString('onerror=1>', $html);
        $this->assertStringContainsString('-thumbnail.webp', $html, 'the thumbnail variant is the preview');
        $this->assertStringNotContainsString('/storage/'.$used->path, $html, 'the original is never an image address');
        $this->assertStringContainsString('40×30', $html);
        $this->assertStringContainsString('1 място', $html);
        $this->assertStringContainsString('неизползвана', $html);
    }

    public function test_a_file_that_is_not_there_says_so_without_an_image(): void
    {
        $this->asset(['missing_at' => now(), 'original_name' => 'gone.jpg']);
        $this->asset(['processing_status' => 'pending', 'variants' => null, 'original_name' => 'wait.jpg']);
        $this->asset(['processing_status' => 'failed', 'variants' => null, 'original_name' => 'bad.jpg']);
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(ListMedia::class)->html();

        $this->assertStringContainsString('Файлът липсва', $html);
        $this->assertStringContainsString('Обработва се', $html);
        $this->assertStringContainsString('Грешка при обработка', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    // ---- the details -------------------------------------------------------------------------------------------------------------

    public function test_the_details_list_where_the_picture_is_used_with_links_and_the_facts(): void
    {
        $staff = $this->actingAsStaff('Administrator');
        $asset = $this->asset(['original_name' => 'dress.jpg', 'alt_text' => 'A dress', 'uploaded_by' => (int) $staff->id, 'size_bytes' => 2097152]);
        $product = $this->product('Рокля');
        ProductMediaModel::query()->create(['product_id' => $product->id, 'media_id' => $asset->id, 'sort_order' => 0, 'autoplay' => false]);
        $other = $this->asset();

        Livewire::test(ListMedia::class)->mountTableAction('details', $asset)->assertTableActionMounted(['name' => 'details', 'context' => ['table' => true, 'recordKey' => (string) $asset->id]]);
        $html = $this->detailsHtml($asset);

        $this->assertStringContainsString('Продукт „Рокля“ — главна снимка', $html);
        $this->assertStringContainsString('href="'.\App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $product->id]).'"', $html);
        $this->assertStringContainsString('dress.jpg', $html);
        $this->assertStringContainsString('2.00 MB', $html);
        $this->assertStringContainsString('40×30', $html);
        $this->assertStringContainsString((string) $staff->name, $html);
        $this->assertStringContainsString('-medium.webp', $html, 'the preview is the medium variant');
        $this->assertStringContainsString('-large.webp', $html, 'the copy button holds the large variant');
        $this->assertStringContainsString('Изтриване — скоро', $html);

        $none = $this->detailsHtml($other);
        $this->assertStringContainsString('Не се ползва никъде.', $none);
    }

    public function test_the_copy_button_is_disabled_when_the_picture_is_not_ready(): void
    {
        $this->actingAsStaff('Administrator');
        $pending = $this->asset(['processing_status' => 'pending', 'variants' => null]);

        $html = $this->detailsHtml($pending);

        $this->assertMatchesRegularExpression('/<button[^>]*class="ml-btn"[^>]*disabled/s', $html);
        $this->assertStringContainsString('Файлът още не е готов.', $html);
    }

    public function test_the_alt_text_is_saved_trimmed_through_the_domain_and_logged_without_the_text(): void
    {
        $this->activityLogOn();
        $staff = $this->actingAsStaff('Administrator');
        $asset = $this->asset(['original_name' => 'keep-me.jpg', 'uploaded_by' => 7]);

        Livewire::test(ListMedia::class)
            ->callTableAction('details', $asset, ['alt_text' => '  A red summer dress  '])
            ->assertHasNoTableActionErrors();

        $fresh = $asset->fresh();
        $this->assertSame('A red summer dress', $fresh->alt_text);
        $this->assertSame('keep-me.jpg', $fresh->original_name, 'the facts of the asset are not touched');
        $this->assertSame(7, (int) $fresh->uploaded_by);
        $this->assertNotEmpty($fresh->variants);

        $log = DB::table('activity_log')->where('entity_type', 'media_asset')->where('entity_id', (string) $asset->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame((string) $staff->id, (string) $log[0]->staff_id);
        $this->assertSame('alt_text', $log[0]->field);
        $this->assertStringNotContainsString('summer', json_encode($log[0]), 'the text itself is never logged');

        Livewire::test(ListMedia::class)->callTableAction('details', $asset, ['alt_text' => '']);
        $this->assertNull($asset->fresh()->alt_text, 'an empty text clears it');
    }

    public function test_an_alt_text_with_a_newline_or_over_200_characters_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsStaff('Administrator');
        $asset = $this->asset(['alt_text' => 'before']);

        Livewire::test(ListMedia::class)->callTableAction('details', $asset, ['alt_text' => "two\nlines"])->assertHasTableActionErrors(['alt_text']);
        Livewire::test(ListMedia::class)->callTableAction('details', $asset, ['alt_text' => str_repeat('я', 201)])->assertHasTableActionErrors(['alt_text']);

        $this->assertSame('before', $asset->fresh()->alt_text);
    }

    // ---- the number of queries ---------------------------------------------------------------------------------------------------

    /** Counts the queries of rendering the page once (the whole Livewire request, with its 403 check and the list), for the data in place. */
    private function queriesOfThePage(): int
    {
        $this->actingAsStaff('Administrator');
        Livewire::test(ListMedia::class); // warms the container-wide caches (settings, permissions) that are not the page's own

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListMedia::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_page_makes_a_fixed_number_of_queries_however_many_cards_and_usages_there_are(): void
    {
        foreach (range(1, 3) as $i) {
            $this->asset();
        }
        $small = $this->queriesOfThePage();

        $product = $this->product('Рокля');
        foreach (range(1, 60) as $i) {
            $asset = $this->asset();
            $i % 2 === 0 && ProductMediaModel::query()->create(['product_id' => $product->id, 'media_id' => $asset->id, 'sort_order' => $i, 'autoplay' => false]);
            $i % 5 === 0 && BrandModel::query()->create(['name' => "B{$i}", 'slug' => "b{$i}", 'logo_media_asset_id' => $asset->id]);
            $i % 7 === 0 && HtmlBlockModel::query()->create(['title' => "H{$i}", 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $asset->id, 'alt' => '', 'url' => '']]]);
        }
        $big = $this->queriesOfThePage();

        $this->assertSame($small, $big, "3 cards: {$small} queries, 48 cards with usages: {$big}");
        $this->assertLessThanOrEqual(10, $big, 'and not many: the list, its count, one per usage provider and the page frame');
    }
}
