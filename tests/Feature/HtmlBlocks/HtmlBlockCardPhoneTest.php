<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockCard;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Filament\Resources\HtmlBlockResource;
use App\Media\DeleteOutcome;
use App\Media\MediaDeleter;
use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HB-M1: «Пропорция на телефон» and «Снимка за телефон» of a «Снимка с текст» card: closed list + optional image, a phone-only media rule in the token stylesheet (below 48rem), the usage of the phone
 * picture, and the promise that a card with neither prints exactly what it printed before.
 */
class HtmlBlockCardPhoneTest extends TestCase
{
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
    }

    /** An image with three renditions of different widths (the shared fixture has one width). */
    private function wideAsset(): MediaAssetModel
    {
        $uuid = uniqid('', true);
        $variants = [];

        foreach (['thumbnail' => 400, 'medium' => 900, 'large' => 1600] as $tier => $width) {
            $variants[] = ['tier' => $tier, 'width' => $width, 'height' => $width, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"];
        }

        return MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 2048, 'width' => 1600, 'height' => 1600]);
    }

    /** @param array<string, mixed> $over */
    private function make(array $over = []): HtmlBlockModel
    {
        return HtmlBlockModel::query()->create(['title' => 'Card', 'kind' => 'card', 'card' => $over + ['picture' => $this->wideAsset()->id, 'title' => 'Spring', 'height' => 400]]);
    }

    /** @return list<string> the rules of the block */
    private function rules(HtmlBlockModel $block): array
    {
        return array_values(array_filter(HtmlBlockStyle::storedRules(), fn (string $r): bool => str_contains($r, ".sf-hb-{$block->id}{") || str_contains($r, ".sf-hb-{$block->id}.sf-pc{")));
    }

    // ---- default: nothing changes ----------------------------------------------------------------------------------------------------

    public function test_a_card_without_phone_settings_has_one_rule_no_media_rule_and_the_same_markup(): void
    {
        $block = $this->make();
        $rules = $this->rules($block);

        $this->assertCount(1, $rules);
        $this->assertStringStartsWith(".sf-hb-{$block->id}{background-image:url(", $rules[0]);
        $this->assertStringNotContainsString('@media', $rules[0]);

        $html = HtmlBlockModel::renderMany([(int) $block->id])[(int) $block->id];
        $this->assertStringContainsString('sf-pc-ht-400', $html, 'the clamp class stays: the desktop AND an as_desktop phone are today\'s');
        $this->assertStringNotContainsString('phone', $html);
        $this->assertSame('as_desktop', $block->card['phone_ratio']);
        $this->assertNull($block->card['phone_picture']);
    }

    public function test_an_old_stored_card_with_neither_key_still_validates_and_gets_the_defaults(): void
    {
        $asset = $this->pickerAsset();
        $old = ['picture' => $asset->id, 'title' => 'T', 'subtitle' => '', 'text' => '', 'link' => '', 'alt' => ''];

        $this->assertNotNull(HtmlBlockCard::active($old));
        $this->assertSame('as_desktop', HtmlBlockCard::active($old)['phone_ratio']);
    }

    // ---- ratio -----------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function ratios(): array
    {
        return ['1:1' => ['1:1', '1 / 1'], '4:5' => ['4:5', '4 / 5'], '3:4' => ['3:4', '3 / 4'], '2:3' => ['2:3', '2 / 3']];
    }

    #[DataProvider('ratios')]
    public function test_a_phone_ratio_adds_one_media_rule_with_the_ratio_and_no_height_clamp(string $ratio, string $css): void
    {
        $block = $this->make(['phone_ratio' => $ratio]);
        $rules = $this->rules($block);

        $this->assertCount(2, $rules);
        $this->assertStringNotContainsString('@media', $rules[0], 'the desktop rule is unchanged');
        $this->assertSame("@media (max-width:47.99rem){.sf-hb-{$block->id}.sf-pc{aspect-ratio:{$css};min-height:0;background-size:cover}}", $rules[1]);
        $this->assertStringNotContainsString('min(', $rules[1]);
        $this->assertTrue(HtmlBlockStyle::isValidRule($rules[1]));
    }

    public function test_an_unknown_ratio_is_refused(): void
    {
        foreach (['16:9', '1:2', 'tall', '1 / 1', 5] as $bad) {
            [, $errors] = HtmlBlockCard::validate(['picture' => 1, 'title' => 'T', 'phone_ratio' => $bad]);
            $this->assertArrayHasKey('phone_ratio', $errors, (string) json_encode($bad));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->make(['phone_ratio' => '16:9']);
    }

    // ---- phone picture ---------------------------------------------------------------------------------------------------------------

    public function test_a_phone_picture_replaces_the_background_only_in_the_media_rule_with_a_phone_sized_rendition(): void
    {
        $main = $this->wideAsset();
        $phone = $this->wideAsset();
        $block = $this->make(['picture' => $main->id, 'phone_picture' => $phone->id]);
        $rules = $this->rules($block);

        $this->assertCount(2, $rules);
        $this->assertStringContainsString(basename((string) $main->path, '.jpg').'-large.webp', $rules[0], 'desktop: the main picture, the largest rendition as before');
        $this->assertStringNotContainsString(basename((string) $phone->path, '.jpg'), $rules[0]);
        $this->assertStringStartsWith('@media (max-width:47.99rem){', $rules[1]);
        $this->assertStringContainsString(basename((string) $phone->path, '.jpg').'-medium.webp', $rules[1], 'phones: the smallest rendition at least 800 px wide');
        $this->assertStringNotContainsString('aspect-ratio', $rules[1], 'as_desktop with a phone picture: only the picture changes');
        $this->assertTrue(HtmlBlockStyle::isValidRule($rules[1]));
    }

    public function test_a_phone_picture_and_a_ratio_make_one_rule_with_both(): void
    {
        $phone = $this->wideAsset();
        $rule = $this->rules($this->make(['phone_picture' => $phone->id, 'phone_ratio' => '4:5']))[1];

        $this->assertStringContainsString('background-image:url(/storage/', $rule);
        $this->assertStringContainsString(';aspect-ratio:4 / 5;min-height:0;background-size:cover}}', $rule);
        $this->assertTrue(HtmlBlockStyle::isValidRule($rule));
    }

    public function test_the_validator_of_the_stylesheet_refuses_anything_else_in_a_phone_rule(): void
    {
        $id = 7;

        foreach ([
            "@media (max-width:47.99rem){.sf-hb-{$id}.sf-pc{aspect-ratio:16 / 9;min-height:0;background-size:cover}}",
            "@media (max-width:47.99rem){.sf-hb-{$id}.sf-pc{background-image:url(https://evil.test/a.jpg)}}",
            "@media (max-width:47.99rem){.sf-hb-{$id}.sf-pc{aspect-ratio:1 / 1;min-height:0;background-size:cover;color:red}}",
            "@media (max-width:1px){.sf-hb-{$id}.sf-pc{aspect-ratio:1 / 1;min-height:0;background-size:cover}}",
            "@media (max-width:47.99rem){.sf-hb-{$id}.sf-pc{aspect-ratio:1 / 1;min-height:0;background-size:cover}}}body{x:y}",
        ] as $bad) {
            $this->assertFalse(HtmlBlockStyle::isValidRule($bad), $bad);
        }
    }

    public function test_a_phone_picture_that_is_gone_leaves_the_ratio_and_the_main_picture(): void
    {
        $phone = $this->wideAsset();
        $block = $this->make(['phone_picture' => $phone->id, 'phone_ratio' => '1:1']);
        \DB::table('catalog_media')->where('id', $phone->id)->delete();

        $rules = $this->rules($block);
        $this->assertCount(2, $rules);
        $this->assertStringNotContainsString('background-image', $rules[1]);
        $this->assertStringContainsString('aspect-ratio:1 / 1', $rules[1]);
    }

    // ---- validation of the form ------------------------------------------------------------------------------------------------------

    public function test_the_form_refuses_a_missing_or_non_image_phone_picture(): void
    {
        $good = $this->wideAsset();
        $video = $this->pickerVideo();

        foreach ([999999, $video->id] as $bad) {
            try {
                HtmlBlockResource::cardFromForm(['picture' => $good->id, 'title' => 'T', 'phone_picture' => $bad], null);
                $this->fail('a phone picture that is not an image of the library was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('data.card.phone_picture', $e->errors());
            }
        }

        [, $errors] = HtmlBlockCard::validate(['picture' => $good->id, 'title' => 'T', 'phone_picture' => 'abc']);
        $this->assertArrayHasKey('phone_picture', $errors);

        $stored = HtmlBlockResource::cardFromForm(['picture' => $good->id, 'title' => 'T', 'phone_picture' => (string) $good->id, 'phone_ratio' => '2:3'], null);
        $this->assertSame($good->id, $stored['phone_picture']);
    }

    // ---- usage -----------------------------------------------------------------------------------------------------------------------

    public function test_a_phone_only_asset_is_used_listed_and_cannot_be_deleted(): void
    {
        $phone = $this->wideAsset();
        $main = $this->wideAsset();
        $block = $this->make(['picture' => $main->id, 'phone_picture' => $phone->id]);
        $registry = app(MediaUsageRegistry::class);

        $this->assertSame([(int) $phone->id => 1], $registry->counts([(int) $phone->id]));
        $this->assertCount(1, $registry->usagesOf((int) $phone->id));
        $this->assertStringContainsString($block->title, $registry->usagesOf((int) $phone->id)[0]->label);

        $outcome = app(MediaDeleter::class)->delete((int) $phone->id);
        $this->assertSame(DeleteOutcome::IN_USE, $outcome->status);
        $this->assertNotNull(MediaAssetModel::query()->find($phone->id));
    }

    public function test_the_same_asset_as_both_pictures_counts_once(): void
    {
        $asset = $this->wideAsset();
        $this->make(['picture' => $asset->id, 'phone_picture' => $asset->id]);

        $this->assertSame([(int) $asset->id => 1], app(MediaUsageRegistry::class)->counts([(int) $asset->id]));
    }
}
