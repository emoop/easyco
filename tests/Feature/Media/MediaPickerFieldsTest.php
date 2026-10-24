<?php

namespace Tests\Feature\Media;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockPictures;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Filament\Design\FieldForms;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Media\DeleteOutcome;
use App\Media\MediaDeleter;
use App\Media\MediaFilters;
use App\Media\Usage\HtmlBlockBody;
use App\Media\Usage\MediaUsageRegistry;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\FieldKinds;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-2: the picker in the places that show a picture — the brand logo, the pictures of an HTML block, the text editor's picture button, the Design `media` kind, and the `multiple()` field the
 * product gallery will use. A picture is always an id of an asset already in the library; «Махни» only detaches.
 */
class MediaPickerFieldsTest extends TestCase
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
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        Queue::fake();
    }

    private function asset(array $override = []): MediaAssetModel
    {
        $uuid = uniqid();
        $variants = array_map(fn (array $t): array => ['tier' => $t[0], 'width' => $t[1], 'height' => $t[2], 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$t[0]}.webp"], [['thumbnail', 400, 300], ['medium', 900, 675], ['large', 1600, 1200]]);

        return MediaAssetModel::query()->create($override + ['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 2048, 'width' => 40, 'height' => 30]);
    }

    // ---- the brand logo ----------------------------------------------------------------------------------------------------------

    public function test_a_brand_is_created_with_a_picked_logo_and_the_table_shows_it(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->asset();

        Livewire::test(CreateBrand::class)->fillForm(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $logo->id])->call('create')->assertHasNoFormErrors();

        $brand = BrandModel::query()->firstOrFail();
        $this->assertSame((int) $logo->id, (int) $brand->logo_media_asset_id);
        $this->assertSame($logo->path, BrandResource::logoPath($brand), 'the table column keeps showing the logo');
        $this->assertSame(0, MediaAssetModel::query()->count() - 1, 'no new asset: the picker hands over an id');
    }

    public function test_the_logo_can_be_changed_and_removing_it_only_detaches(): void
    {
        $this->actingAsStaff('Administrator');
        $first = $this->asset();
        $second = $this->asset();
        $brand = BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $first->id]);

        Livewire::test(EditBrand::class, ['record' => $brand->id])->assertFormSet(['logo_media_asset_id' => $first->id])->fillForm(['logo_media_asset_id' => $second->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $second->id, (int) $brand->fresh()->logo_media_asset_id);

        Livewire::test(EditBrand::class, ['record' => $brand->id])->fillForm(['logo_media_asset_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($brand->fresh()->logo_media_asset_id);

        $this->assertNotNull(MediaAssetModel::query()->find($second->id), '«Махни» never deletes the file');
        $this->assertContains((int) $second->id, MediaFilters::query(['use' => 'unused'])->pluck('id')->map(fn ($i): int => (int) $i)->all(), 'it is only «неизползвана» now');
    }

    public function test_a_video_a_missing_row_or_garbage_is_a_field_error_and_no_brand_is_made(): void
    {
        $this->actingAsStaff('Administrator');
        $video = $this->asset(['type' => 'video', 'variants' => null]);

        foreach ([$video->id, 987654, 'abc', -4] as $bad) {
            Livewire::test(CreateBrand::class)->fillForm(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $bad])->call('create')->assertHasFormErrors(['logo_media_asset_id']);
        }

        $this->assertSame(0, BrandModel::query()->count());
    }

    public function test_the_field_shows_the_empty_box_and_the_filled_row_with_the_three_controls_and_the_hint(): void
    {
        $this->actingAsStaff('Administrator');
        $logo = $this->asset(['original_name' => 'nike-logo.png', 'width' => 600, 'height' => 200]);
        $brand = BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $logo->id]);

        $empty = Livewire::test(CreateBrand::class)->html();
        $this->assertStringContainsString('Няма избрана снимка', $empty);
        $this->assertStringContainsString('Избери от медията', $empty);
        $this->assertStringContainsString('Качи нова', $empty);
        $this->assertStringNotContainsString('Махни', $empty, 'nothing to remove yet');
        $this->assertStringContainsString('Снимката остава в Медия.', $empty);

        $filled = Livewire::test(EditBrand::class, ['record' => $brand->id])->html();
        $this->assertStringContainsString('-thumbnail.webp', $filled, 'the thumbnail variant, never the original');
        $this->assertStringNotContainsString('/storage/'.$logo->path, $filled);
        $this->assertStringContainsString('nike-logo.png', $filled);
        $this->assertStringContainsString('600×200', $filled);
        foreach (['Смени', 'Качи нова', 'Махни'] as $control) {
            $this->assertStringContainsString($control, $filled);
        }
        $this->assertStringNotContainsString('Няма избрана снимка', $filled);
    }

    public function test_the_picker_modal_opens_from_the_field_and_is_the_browser_component(): void
    {
        $this->actingAsStaff('Administrator');
        $this->asset();

        $component = Livewire::test(CreateBrand::class)->mountAction(TestAction::make('pick')->schemaComponent('logo_media_asset_id', 'form'));

        $this->assertSame('pick', $component->instance()->mountedActions[0]['name'] ?? null);
        $this->assertStringContainsString('logo_media_asset_id', (string) ($component->instance()->mountedActions[0]['context']['schemaComponent'] ?? ''));
    }

    // ---- HTML block pictures -----------------------------------------------------------------------------------------------------

    public function test_a_block_keeps_the_ids_of_the_picked_pictures_and_the_stored_shape_is_unchanged(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset();
        $b = $this->asset();

        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Banner', 'body' => '', 'pictures' => [
                'a' => ['media_asset_id' => $a->id, 'alt' => 'Boys', 'url' => '/product-category/boys'],
                'b' => ['media_asset_id' => $b->id, 'alt' => '', 'url' => ''],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertEquals([['media_asset_id' => $a->id, 'alt' => 'Boys', 'url' => '/product-category/boys'], ['media_asset_id' => $b->id, 'alt' => '', 'url' => '']], $block->pictures);
    }

    public function test_a_picked_picture_brings_its_alt_text_when_the_block_has_none(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset(['alt_text' => 'A red dress']);
        $b = $this->asset(['alt_text' => 'Another']);

        $component = Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Banner', 'body' => '<p>x</p>', 'pictures' => ['a' => ['media_asset_id' => null, 'alt' => '', 'url' => ''], 'b' => ['media_asset_id' => null, 'alt' => 'My own alt', 'url' => '']]])
            ->set('data.pictures.a.media_asset_id', $a->id)
            ->set('data.pictures.b.media_asset_id', $b->id);

        $component->assertFormSet(function (array $state): void {
            $this->assertSame('A red dress', $state['pictures']['a']['alt'], 'the empty alt is filled from the asset');
            $this->assertSame('My own alt', $state['pictures']['b']['alt'], 'the block\'s own alt is never overwritten');
        });
    }

    public function test_a_video_id_in_a_block_picture_is_refused(): void
    {
        $this->actingAsStaff('Administrator');
        $video = $this->asset(['type' => 'video', 'variants' => null]);

        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'Banner', 'body' => '<p>x</p>', 'pictures' => ['a' => ['media_asset_id' => $video->id, 'alt' => '', 'url' => '']]])
            ->call('create')
            ->assertHasFormErrors(['pictures.a.media_asset_id']);

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_the_rendered_block_is_exactly_what_the_renderer_has_always_printed_for_the_same_asset(): void
    {
        $asset = $this->asset(['path' => 'uploads/2026/10/golden.jpg', 'alt_text' => '', 'variants' => [['tier' => 'medium', 'width' => 900, 'height' => 675, 'quality' => 80, 'path' => 'uploads/2026/10/golden-medium.webp']]]);
        $pictures = [['media_asset_id' => (int) $asset->id, 'alt' => 'Boys', 'url' => '/product-category/boys']];

        $html = HtmlBlockPictures::html($pictures, [$asset->id => $asset]);

        $this->assertSame('<figure><a href="/product-category/boys"><img src="/storage/uploads/2026/10/golden-medium.webp" alt="Boys" width="900" height="675"></a></figure>', $html, 'the renderer (untouched by ML-2) prints the stored shape the same way');
    }

    // ---- the text editor's picture button ------------------------------------------------------------------------------------------

    public function test_the_editor_has_the_picture_tool_and_no_longer_the_loose_file_attachment(): void
    {
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(CreateHtmlBlock::class)->html();

        $this->assertStringContainsString("mountAction('mediaImage'", $html);
        $this->assertStringNotContainsString("mountAction('attachFiles'", $html);
    }

    public function test_the_picture_action_inserts_an_image_node_with_the_size_asked_for(): void
    {
        $this->actingAsStaff('Administrator');
        $asset = $this->asset(['alt_text' => 'A dress']);

        foreach (['medium' => [900, 675, 'medium'], 'large' => [1600, 1200, 'large']] as $size => [$w, $h, $tier]) {
            Livewire::test(CreateHtmlBlock::class)
                ->callAction(TestAction::make('mediaImage')->schemaComponent('body', 'form'), ['media_id' => $asset->id, 'size' => $size], ['editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]])
                ->assertDispatched('run-rich-editor-commands', function (string $event, array $params) use ($asset, $w, $h, $tier): bool {
                    $node = $params['commands'][0]['arguments'][0] ?? [];

                    return ($params['commands'][0]['name'] ?? null) === 'insertContent'
                        && ($node['type'] ?? null) === 'image'
                        && str_starts_with($node['attrs']['src'], '/storage/uploads/2026/10/')
                        && str_ends_with($node['attrs']['src'], "-{$tier}.webp")
                        && ($node['attrs']['alt'] ?? null) === 'A dress'
                        && ($node['attrs']['width'] ?? null) === $w
                        && ($node['attrs']['height'] ?? null) === $h
                        && ! array_key_exists('id', $node['attrs']);
                });
        }
    }

    public function test_the_picture_action_refuses_a_video_a_missing_row_and_a_picture_with_no_sizes_yet(): void
    {
        $this->actingAsStaff('Administrator');
        $video = $this->asset(['type' => 'video', 'variants' => null]);
        $pending = $this->asset(['processing_status' => 'pending', 'variants' => null]);

        foreach ([$video->id, 987654, $pending->id] as $id) {
            Livewire::test(CreateHtmlBlock::class)
                ->callAction(TestAction::make('mediaImage')->schemaComponent('body', 'form'), ['media_id' => $id, 'size' => 'large'], ['editorSelection' => ['type' => 'text', 'anchor' => 1, 'head' => 1]])
                ->assertNotDispatched('run-rich-editor-commands');
        }
    }

    public function test_a_body_with_such_a_picture_survives_the_sanitizer_on_save_and_on_render(): void
    {
        $img = '<img src="/storage/uploads/2026/10/abc-large.webp" alt="A dress" width="1600" height="1200">';
        $block = HtmlBlockModel::create(['title' => 'Text', 'body' => '<p>Before</p>'.$img.'<p>After</p>']);

        $this->assertStringContainsString($img, $block->fresh()->body, 'saved as it is');
        $this->assertStringContainsString('<img loading="lazy" decoding="async" src="/storage/uploads/2026/10/abc-large.webp" alt="A dress" width="1600" height="1200">', HtmlBlockSanitizer::render($block->fresh()->body));
    }

    // ---- HtmlBlockBody ------------------------------------------------------------------------------------------------------------

    private function blockWithBodyPicture(MediaAssetModel $asset, string $tier = 'large', string $host = ''): HtmlBlockModel
    {
        $variant = collect($asset->variants)->firstWhere('tier', $tier);

        return HtmlBlockModel::create(['title' => 'Промо', 'body' => '<p>x</p><img src="'.$host.'/storage/'.$variant['path'].'" alt="a" width="100" height="100">']);
    }

    public function test_the_provider_finds_a_picture_inside_a_text_by_its_variant_or_its_original_and_lists_the_block(): void
    {
        $asset = $this->asset();
        $other = $this->asset();
        $block = $this->blockWithBodyPicture($asset, 'medium');
        HtmlBlockModel::create(['title' => 'Original', 'body' => '<img src="/storage/'.$other->path.'" alt="" width="10" height="10">']);

        $provider = new HtmlBlockBody;

        $this->assertSame([$asset->id => 1, $other->id => 1], $provider->counts([$asset->id, $other->id, 99999]));
        $usages = $provider->usagesOf((int) $asset->id);
        $this->assertSame(['HTML блок „Промо“ — в текста'], array_map(fn ($u) => $u->label, $usages));
        $this->assertStringContainsString('/'.$block->id.'/edit', (string) $usages[0]->editUrl);
    }

    public function test_the_provider_ignores_a_loose_old_file_and_the_address_of_another_site(): void
    {
        $asset = $this->asset();
        HtmlBlockModel::create(['title' => 'Old', 'body' => '<img src="/storage/html-blocks/old.jpg" alt="" width="10" height="10">']);
        HtmlBlockModel::query()->insert(['title' => 'Foreign', 'body' => '<img src="https://evil.example/storage/'.$asset->path.'" alt="">', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], (new HtmlBlockBody)->counts([$asset->id]));
    }

    public function test_the_registry_reads_the_blocks_once_for_both_block_providers(): void
    {
        $asset = $this->asset();
        $this->blockWithBodyPicture($asset);
        HtmlBlockModel::create(['title' => 'Pics', 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $asset->id, 'alt' => '', 'url' => '']]]);

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $counts = (new MediaUsageRegistry)->counts([$asset->id]);

        $this->assertSame(2, $counts[$asset->id], 'once in a picture list, once in a text');
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `html_blocks`')), 'ONE read of html_blocks for both providers');
    }

    public function test_a_picture_used_inside_a_text_cannot_be_deleted(): void
    {
        $asset = $this->asset();
        $this->blockWithBodyPicture($asset);

        $outcome = app(MediaDeleter::class)->delete((int) $asset->id);

        $this->assertSame(DeleteOutcome::IN_USE, $outcome->status);
        $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        $this->assertNotContains((int) $asset->id, MediaFilters::query(['use' => 'unused'])->pluck('id')->map(fn ($i): int => (int) $i)->all());
    }

    // ---- the Design `media` kind and the multiple() field --------------------------------------------------------------------------

    public function test_the_media_kind_of_the_design_screen_is_the_picker(): void
    {
        $field = FieldForms::make('logo', ['kind' => 'media', 'label' => 'header.settings.logo']);

        $this->assertInstanceOf(MediaPicker::class, $field);
        $this->assertFalse($field->isMultiple());
    }

    public function test_a_design_field_with_the_media_kind_renders_picks_and_refuses_a_video(): void
    {
        $asset = $this->asset();
        $video = $this->asset(['type' => 'video', 'variants' => null]);

        $host = Livewire::test(PickerTestHost::class, ['spec' => ['kind' => 'media', 'label' => 'header.settings.logo']]);

        $this->assertStringContainsString('Няма избрана снимка', $host->html());

        $host->set('data.field', $asset->id)->call('check')->assertHasNoErrors();
        $this->assertStringContainsString('-thumbnail.webp', $host->html());
        $this->assertSame((int) $asset->id, (int) $host->get('data.field'));

        $host->set('data.field', $video->id)->call('check')->assertHasErrors(['data.field']);

        // the design validator's own kind check (an id) stays as it was
        $fails = [];
        $this->assertSame((int) $asset->id, FieldKinds::validate((string) $asset->id, ['kind' => 'media'], 'x', function (string $path, string $code) use (&$fails): void {
            $fails[] = $code;
        }));
        $this->assertSame([], $fails);
    }

    public function test_the_design_provider_picks_up_a_media_id_from_a_draft_and_a_published_layout(): void
    {
        $inDraft = $this->asset();
        $inPublished = $this->asset();
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode(['desktop' => ['rows' => [['cells' => [['nodes' => [['type' => 'logo', 'settings' => ['media_asset_id' => $inDraft->id]]]]]]]]]));
        $settings->set(AppearanceSettings::FOOTER_PUBLISHED_KEY, json_encode(['upper' => ['rows' => [['media_id' => $inPublished->id]]]]));

        $counts = (new MediaUsageRegistry)->counts([$inDraft->id, $inPublished->id]);

        $this->assertSame([$inDraft->id => 1, $inPublished->id => 1], array_map('intval', $counts) + []);
    }

    public function test_the_multiple_field_shows_a_row_of_tiles_each_with_its_own_remove_and_the_add_buttons(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $c = $this->asset();

        $host = Livewire::test(PickerTestHost::class, ['spec' => ['kind' => 'media', 'label' => 'x'], 'multiple' => 2]);
        $this->assertStringContainsString('Няма избрана снимка', $host->html());

        $host->set('data.field', [$a->id, $b->id]);
        $html = $host->html();
        $this->assertSame(2, substr_count($html, 'class="mpf-tile"'));
        $this->assertSame(2, substr_count($html, 'class="mpf-x"'));
        $this->assertStringContainsString('Добави от медията', $html);
        $this->assertStringContainsString('Качи нови', $html);

        $host->call('check')->assertHasNoErrors();
        $host->set('data.field', [$a->id, $b->id, $c->id])->call('check')->assertHasErrors(['data.field']);
        $host->set('data.field', [$a->id, $a->id])->call('check')->assertHasErrors(['data.field']);
    }
}

/** A tiny Livewire host for one picker field, so the field can be rendered and validated on its own. */
class PickerTestHost extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed> */
    public array $spec = [];

    public int $multiple = 0;

    /** @var array<string, mixed>|null */
    public ?array $data = ['field' => null];

    public function mount(array $spec, int $multiple = 0): void
    {
        $this->spec = $spec;
        $this->multiple = $multiple;
        $this->data = ['field' => $multiple > 0 ? [] : null];
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        $field = FieldForms::make('field', $this->spec);
        $this->multiple > 0 && $field->multiple($this->multiple);

        return $schema->statePath('data')->components([$field]);
    }

    public function check(): void
    {
        $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
