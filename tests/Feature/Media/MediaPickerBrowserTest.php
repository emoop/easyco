<?php

namespace Tests\Feature\Media;

use App\Livewire\MediaPickerBrowser;
use App\Rules\MediaAssetId;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-2: the media picker's server side. The MediaAssetId rule (a picture field accepts only ids of existing PICTURES, at most `max`), and the modal's Livewire component: the library tab (search,
 * «Само неизползвани», «Още», only images that are ready or processing, select / unselect / double click), the alt text saved on «Избери», the upload tab, the number of queries, and access.
 */
class MediaPickerBrowserTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    private const FIELD = 'data.logo_media_asset_id';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
    }

    private function asset(array $override = []): MediaAssetModel
    {
        $uuid = uniqid();
        $variants = array_map(fn (string $tier): array => ['tier' => $tier, 'width' => 100, 'height' => 100, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"], ['thumbnail', 'medium', 'large']);

        return MediaAssetModel::query()->create($override + ['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 2048, 'width' => 40, 'height' => 30]);
    }

    /** @return list<int> */
    private function listed($component): array
    {
        return $component->viewData('rows')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function browser(array $params = [])
    {
        return Livewire::test(MediaPickerBrowser::class, $params + ['field' => self::FIELD]);
    }

    // ---- the rule ----------------------------------------------------------------------------------------------------------------

    public function test_the_rule_accepts_an_existing_picture_and_refuses_everything_else(): void
    {
        $image = $this->asset();
        $social = $this->asset(['type' => 'social_image']);
        $video = $this->asset(['type' => 'video', 'variants' => null]);
        $check = fn (mixed $value, ?int $max = null): bool => Validator::make(['v' => $value], ['v' => [new MediaAssetId($max)]])->passes();

        $this->assertTrue($check($image->id));
        $this->assertTrue($check((string) $social->id), 'a numeric string is an id too');
        $this->assertTrue($check(null) && $check('') && $check([]), 'empty is the field\'s own `required` to judge');
        $this->assertFalse($check($video->id), 'a video id is refused for a picture field');
        $this->assertFalse($check(987654), 'a row that does not exist');
        $this->assertFalse($check(0) || $check(-3) || $check('abc') || $check('1.5') || $check(1.5) || $check(true), 'not a positive integer');
        $this->assertFalse($check([$image->id]), 'a list for a single field');
        $this->assertTrue($check([$image->id, $social->id], 3));
        $this->assertFalse($check([$image->id, $social->id], 1), 'more than the field\'s max');
        $this->assertFalse($check([$image->id, $image->id], 3), 'the same id twice');
        $this->assertFalse($check([$image->id, $video->id], 3), 'one video in the list');
        $this->assertFalse($check($image->id, 3), 'a single value for a multiple field');
    }

    // ---- the library tab ---------------------------------------------------------------------------------------------------------

    public function test_only_images_that_are_ready_or_processing_are_listed(): void
    {
        $this->actingAsStaff('Administrator');
        $ready = $this->asset();
        $pending = $this->asset(['processing_status' => 'pending', 'variants' => null]);
        $processing = $this->asset(['processing_status' => 'processing', 'variants' => null]);
        $this->asset(['type' => 'video', 'variants' => null]);
        $this->asset(['processing_status' => 'failed', 'variants' => null]);
        $this->asset(['missing_at' => now()]);
        $this->asset(['variants' => null]);

        $ids = $this->listed($this->browser());

        $this->assertEqualsCanonicalizing([$ready->id, $pending->id, $processing->id], $ids);
        $this->assertStringContainsString('Обработва се', $this->browser()->html(), 'a picture still being processed says so and stays selectable');
    }

    public function test_search_finds_by_name_and_alt_text_and_treats_wildcards_as_text(): void
    {
        $this->actingAsStaff('Administrator');
        $byName = $this->asset(['original_name' => 'summer-dress.jpg']);
        $byAlt = $this->asset(['original_name' => 'IMG_1.jpg', 'alt_text' => 'A red summer hat']);
        $percent = $this->asset(['original_name' => '50%-off.jpg']);
        $other = $this->asset(['original_name' => 'coat.jpg']);

        $component = $this->browser();
        $this->assertEqualsCanonicalizing([$byName->id, $byAlt->id, $percent->id, $other->id], $this->listed($component));
        $this->assertEqualsCanonicalizing([$byName->id, $byAlt->id], $this->listed($component->set('search', 'summer')));
        $this->assertSame([$percent->id], $this->listed($component->set('search', '%')), 'a % is a character, not a wildcard');
        $this->assertSame([], $this->listed($component->set('search', 'nothing like this')));
    }

    public function test_only_unused_hides_what_a_product_or_a_brand_uses(): void
    {
        $this->actingAsStaff('Administrator');
        $unused = $this->asset();
        $onBrand = $this->asset();
        \EasyCo\Catalog\Persistence\Eloquent\BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $onBrand->id]);

        $component = $this->browser();
        $this->assertEqualsCanonicalizing([$unused->id, $onBrand->id], $this->listed($component));
        $this->assertSame([$unused->id], $this->listed($component->set('onlyUnused', true)));
    }

    public function test_more_adds_24_at_a_time_and_the_button_goes_when_there_is_nothing_more(): void
    {
        $this->actingAsStaff('Administrator');
        foreach (range(1, 30) as $i) {
            $this->asset(['original_name' => sprintf('p%02d.jpg', $i)]);
        }

        $component = $this->browser();
        $this->assertCount(24, $this->listed($component));
        $this->assertTrue($component->viewData('hasMore'));
        $this->assertStringContainsString('Още', $component->html());

        $component->call('more');
        $this->assertCount(30, $this->listed($component));
        $this->assertFalse($component->viewData('hasMore'));
    }

    // ---- selecting ---------------------------------------------------------------------------------------------------------------

    public function test_a_click_selects_a_second_click_unselects_and_another_picture_replaces_in_a_single_field(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset();
        $b = $this->asset();

        $component = $this->browser()->call('toggle', $a->id);
        $this->assertSame([$a->id], $component->get('selected'));
        $this->assertStringContainsString('aria-pressed="true"', $component->html());

        $component->call('toggle', $b->id);
        $this->assertSame([$b->id], $component->get('selected'), 'a single field holds one');

        $component->call('toggle', $b->id);
        $this->assertSame([], $component->get('selected'));
    }

    public function test_a_multiple_field_selects_up_to_its_max_and_the_button_counts_them(): void
    {
        $this->actingAsStaff('Administrator');
        $ids = array_map(fn () => $this->asset()->id, range(1, 3));

        $component = $this->browser(['multiple' => true, 'max' => 2]);
        foreach ($ids as $id) {
            $component->call('toggle', $id);
        }

        $this->assertSame([$ids[0], $ids[1]], $component->get('selected'), 'the third is not added');
        $this->assertStringContainsString('Избери (2)', $component->html());
    }

    public function test_a_double_click_selects_and_confirms(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset();

        $this->browser()->call('choose', $a->id)->assertDispatched('media-picked', field: self::FIELD, ids: [$a->id], size: 'large');
    }

    public function test_the_alt_text_of_the_selected_picture_is_saved_when_choose_is_pressed_and_not_before(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset(['alt_text' => 'old']);

        $component = $this->browser()->call('toggle', $a->id);
        $this->assertSame('old', $component->get('alt'), 'the column shows the asset\'s own alt');

        $component->set('alt', '  A new alt text ');
        $this->assertSame('old', $a->fresh()->alt_text, 'typing saves nothing');

        $component->call('confirm')->assertDispatched('media-picked');
        $this->assertSame('A new alt text', $a->fresh()->alt_text);
    }

    public function test_an_alt_text_that_is_not_plain_is_refused_and_nothing_leaves(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset(['alt_text' => 'old']);

        $this->browser()->call('toggle', $a->id)->set('alt', "two\nlines")->call('confirm')->assertHasErrors(['alt'])->assertNotDispatched('media-picked');
        $this->assertSame('old', $a->fresh()->alt_text);
    }

    public function test_the_size_goes_with_the_event_for_the_text_editor(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset();

        $this->browser(['withSize' => true, 'field' => 'editor'])->call('toggle', $a->id)->set('size', 'medium')->call('confirm')->assertDispatched('media-picked', field: 'editor', ids: [$a->id], size: 'medium');
    }

    public function test_a_tampered_selection_is_refused_by_the_rule_before_anything_leaves(): void
    {
        $this->actingAsStaff('Administrator');
        $video = $this->asset(['type' => 'video', 'variants' => null]);

        // the browser can send any `selected` it likes: it is a public property of the component
        $this->browser()->set('selected', [$video->id])->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser()->set('selected', [987654])->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser()->set('selected', [])->call('confirm')->assertNotDispatched('media-picked');
    }

    public function test_the_locked_properties_cannot_be_changed_from_the_browser(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['field', 'multiple', 'max', 'withSize'] as $property) {
            try {
                $this->browser()->set($property, $property === 'field' ? 'data.other' : 99);
                $this->fail("{$property} could be set");
            } catch (\Throwable $e) {
                $this->assertStringContainsString('locked', strtolower($e->getMessage()));
            }
        }
    }

    // ---- upload ------------------------------------------------------------------------------------------------------------------

    public function test_the_upload_tab_puts_the_new_pictures_selected_on_the_library_tab_and_lists_a_refused_file(): void
    {
        Queue::fake();
        $staff = $this->actingAsStaff('Administrator');
        $damaged = substr($this->jpeg($this->dirtyJpegSegments()), 0, -2);

        $component = $this->browser(['tab' => 'upload'])
            ->set('files', [UploadedFile::fake()->createWithContent('new.png', $this->png()), UploadedFile::fake()->createWithContent('broken.jpg', $damaged), UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41")]);

        $row = MediaAssetModel::query()->where('original_name', 'new.png')->firstOrFail();
        $this->assertSame('library', $component->get('tab'));
        $this->assertSame([(int) $row->id], $component->get('selected'), 'a single field: the first new picture');
        $this->assertSame(['broken.jpg', 'clip.mp4'], array_column($component->get('refused'), 'name'));
        $this->assertSame((int) $staff->id, (int) $row->uploaded_by);
        $this->assertSame(1, MediaAssetModel::query()->count(), 'the damaged file and the video were not stored');
        Queue::assertPushed(ProcessMediaAssetJob::class, 1);
        $this->assertStringContainsString('aria-pressed="true"', $component->html());
    }

    public function test_the_upload_tab_shows_the_refused_files_by_name_with_the_reason(): void
    {
        $this->actingAsStaff('Administrator');

        $component = $this->browser(['tab' => 'upload'])->set('files', [UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]);

        $this->assertSame('upload', $component->get('tab'), 'nothing was stored: still on the same tab');
        $this->assertStringContainsString('evil.svg', $component->html());
        $this->assertSame(0, MediaAssetModel::query()->count());
    }

    // ---- queries and access ------------------------------------------------------------------------------------------------------

    public function test_a_page_of_the_library_costs_the_same_number_of_queries_however_many_pictures_it_shows(): void
    {
        $this->actingAsStaff('Administrator');
        $count = function () {
            $component = $this->browser();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->call('$refresh');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (range(1, 3) as $i) {
            $this->asset();
        }
        $small = $count();

        foreach (range(1, 40) as $i) {
            $asset = $this->asset();
            $i % 3 === 0 && ProductMediaModel::query()->create(['product_id' => $this->productId($i), 'media_id' => $asset->id, 'sort_order' => 0, 'autoplay' => false]);
        }
        $big = $count();

        $this->assertSame($small, $big, "3 pictures: {$small} queries, a full page: {$big}");
        $this->assertLessThanOrEqual(8, $big);
    }

    private function productId(int $i): int
    {
        $product = \EasyCo\Catalog\Product::createSimple("P{$i}", "SKU-{$i}", "slug-{$i}");
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($product);

        return (int) $product->id();
    }

    public function test_a_user_without_content_catalog_or_taxonomy_permission_is_refused_also_on_a_direct_call(): void
    {
        $asset = $this->asset(['alt_text' => 'old']);
        $this->actingAsCustomRole([Permission::CONTENT_MANAGE]);
        $component = $this->browser()->call('toggle', $asset->id)->set('alt', 'sneaky');
        $this->assertSame([$asset->id], $component->get('selected'));

        // the permission is gone by the time the next call arrives: each call is refused (a 403 answer, which the test helper reports as an exception)
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $this->assertFalse(\App\Media\MediaPickerAccess::allowed());

        foreach (['more', 'confirm', 'toggle'] as $method) {
            try {
                $method === 'toggle' ? $component->call('toggle', $asset->id) : $component->call($method);
            } catch (\Throwable) {
                // a refusal is a 403 answer: the test helper may report it as an exception or keep the old state, and either way the state must not have moved
            }
        }

        // and every action refuses on its own, whatever got the request to it
        $browser = new MediaPickerBrowser;

        foreach ([fn () => $browser->toggle($asset->id), fn () => $browser->choose($asset->id), fn () => $browser->more(), fn () => $browser->confirm(), fn () => $browser->updatedFiles()] as $call) {
            try {
                $call();
                $this->fail('an action ran without the permission');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame('old', $asset->fresh()->alt_text, 'nothing was saved');
    }

    public function test_it_cannot_even_be_mounted_without_the_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $this->browser()->assertForbidden();
    }

    public function test_each_of_the_three_permissions_may_use_it(): void
    {
        foreach ([Permission::CONTENT_MANAGE, Permission::PRODUCT_MANAGE, Permission::TAXONOMY_MANAGE] as $permission) {
            $this->actingAsCustomRole([$permission]);

            $this->browser()->assertOk();
        }
    }
}
