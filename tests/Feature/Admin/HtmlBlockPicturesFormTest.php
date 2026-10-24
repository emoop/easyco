<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\TestCase;

/**
 * MENU-5, the admin form «Снимки с връзки»: a repeater of up to 4 pictures (file, alt, address), each file a Media asset made the way a brand logo is; a block of pictures only is allowed,
 * a block with neither body nor picture is not; a bad address, a fifth picture and an unknown asset are field errors; what was saved comes back in the same order.
 */
class HtmlBlockPicturesFormTest extends TestCase
{
    use BuildsMenus;
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        Queue::fake();
        $this->actingAsStaff('Administrator');
    }

    /** @param  array<string, mixed>  $more */
    private function item(?string $path, string $alt = '', string $url = '', ?int $id = null, array $more = []): array
    {
        // ML-2: a picture is the id of an asset that is already in the library (the picker); a path stands for such an asset
        $id ??= $path === null ? null : (int) MediaAssetModel::query()->firstOrCreate(['path' => $path], ['type' => 'image', 'disk' => 'public', 'processing_status' => 'ready', 'variants' => []])->id;

        return ['media_asset_id' => $id, 'alt' => $alt, 'url' => $url] + $more;
    }

    public function test_a_block_of_pictures_only_saves_with_the_ids_of_pictures_already_in_the_library(): void
    {
        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Banner', 'body' => '', 'pictures' => ['a' => $this->item('uploads/2026/10/boys.jpg', 'Boys', '/product-category/boys'), 'b' => $this->item('uploads/2026/10/girls.png', 'Girls', '')]])
            ->call('create')
            ->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertCount(2, $block->pictures);
        $this->assertSame(['Boys', 'Girls'], array_column($block->pictures, 'alt'));
        $this->assertSame(['/product-category/boys', ''], array_column($block->pictures, 'url'));
        $this->assertSame(['uploads/2026/10/boys.jpg', 'uploads/2026/10/girls.png'], MediaAssetModel::query()->whereIn('id', array_column($block->pictures, 'media_asset_id'))->orderBy('id')->pluck('path')->all());
        $this->assertSame(2, MediaAssetModel::query()->count(), 'no new asset: the picker only hands over ids');
        Queue::assertNothingPushed();
    }

    public function test_a_block_with_neither_body_nor_picture_is_refused(): void
    {
        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Empty', 'body' => '', 'pictures' => []])->call('create')->assertHasFormErrors(['body']);

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    /** @return array<string, array{0: string}> */
    public static function badAddresses(): array
    {
        return ['javascript' => ['javascript:alert(1)'], 'data' => ['data:text/html;base64,AAAA'], 'http' => ['http://example.com'], 'protocol-relative' => ['//evil.example/x'], 'mailto' => ['mailto:a@b.test']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAddresses')]
    public function test_a_bad_address_is_refused_on_its_own_field_with_a_message_and_nothing_is_saved(string $url): void
    {
        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Banner', 'body' => '<p>x</p>', 'pictures' => ['a' => $this->item('uploads/2026/10/boys.jpg', 'Boys', $url)]])
            ->call('create')
            ->assertHasFormErrors(['pictures.a.url']);

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_a_fifth_picture_is_refused(): void
    {
        $pictures = [];

        foreach (range(1, 5) as $n) {
            $pictures['p'.$n] = $this->item("uploads/2026/10/{$n}.jpg", "P{$n}");
        }

        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Many', 'body' => '<p>x</p>', 'pictures' => $pictures])->call('create');

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_a_picture_with_an_unknown_asset_is_a_field_error(): void
    {
        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Ghost', 'body' => '<p>x</p>', 'pictures' => ['a' => $this->item(null, 'Ghost', '', 987654)]])
            ->call('create')
            ->assertHasErrors(['data.pictures.a.media_asset_id']);

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_every_field_survives_a_save_and_a_reload_in_the_same_order_and_an_unchanged_picture_keeps_its_asset(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('m/201.jpg', 'x');
        Storage::disk('public')->put('m/202.jpg', 'x');
        $first = $this->makeMenuImage(201);
        $second = $this->makeMenuImage(202);
        $block = HtmlBlockModel::create(['title' => 'Round trip', 'body' => '<p>Body text</p>', 'pictures' => [
            ['media_asset_id' => $second, 'alt' => 'Second asset first', 'url' => 'https://example.com/a'],
            ['media_asset_id' => $first, 'alt' => 'First asset last', 'url' => '/product-category/boys'],
        ]]);

        $page = Livewire::test(EditHtmlBlock::class, ['record' => $block->getRouteKey()]);
        $page->assertFormSet(function (array $state) use ($first, $second): void {
            $items = array_values($state['pictures']);
            $this->assertSame(['Second asset first', 'First asset last'], array_column($items, 'alt'));
            $this->assertSame(['https://example.com/a', '/product-category/boys'], array_column($items, 'url'));
            $this->assertSame([$second, $first], array_map('intval', array_column($items, 'media_asset_id')));
            $this->assertSame('Round trip', $state['title']);
        });

        $assetsBefore = MediaAssetModel::query()->count();
        $page->call('save')->assertHasNoFormErrors();
        $stored = $block->fresh()->pictures;

        $this->assertSame([$second, $first], array_column($stored, 'media_asset_id'), 'the same assets, the same order');
        $this->assertSame(['https://example.com/a', '/product-category/boys'], array_column($stored, 'url'));
        $this->assertSame($assetsBefore, MediaAssetModel::query()->count(), 'no new asset for an unchanged picture');
        Queue::assertNothingPushed();
    }

    public function test_the_page_hints_where_a_link_on_text_and_on_a_picture_is_set(): void
    {
        $html = $this->get(HtmlBlockResource::getUrl('create'))->assertOk()->getContent();

        $this->assertStringContainsString('Pictures with links', $html);
        $this->assertStringContainsString('A link on text is set with the &quot;link&quot; button in the editor; a link on a picture, here.', $html);
        $this->assertStringContainsString('novalidate', $html);
    }
}
