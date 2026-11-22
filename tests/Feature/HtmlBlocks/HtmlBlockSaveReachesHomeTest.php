<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeValidator;
use App\Storefront\Menu\MenuRecompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BUG-1: saving or deleting an HTML block must reach the compiled copy of EVERY published home section that shows it. The trigger (MenuRecompiler::request('html')) used to look for the text `"html_block"`
 * in the published home JSON, which only an `html_block` section has: a home that shows its blocks through an image grid, a blocks carousel or a hero was never rebuilt on a block save (only when a
 * menu, the footer or another layout happened to name a block, which is why a development machine with a mega menu looked fine).
 */
class HtmlBlockSaveReachesHomeTest extends TestCase
{
    use RefreshDatabase;

    private function compiledBlocks(): array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::DESIGN_COMPILED_KEY);

        return HomeCompiler::published(is_string($json) ? $json : null)['blocks'] ?? [];
    }

    private function publish(array $section): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$section]]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    /** The end of the request that saved the block (the debounced rebuild). */
    private function endOfRequest(): void
    {
        app(MenuRecompiler::class)->flush();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function sections(): array
    {
        return [
            'image grid' => [['type' => 'image_grid', 'settings' => ['cells' => ['a' => 'BLOCK']]]],
            'image grid phone list' => [['type' => 'image_grid', 'settings' => ['cells' => ['a' => 'BLOCK'], 'phone_blocks' => ['BLOCK']]]],
            'blocks carousel' => [['type' => 'carousel', 'settings' => ['kind' => 'blocks', 'blocks' => ['BLOCK', 'BLOCK']]]],
            'hero slide' => [['type' => 'hero', 'settings' => ['slides' => [['block' => 'BLOCK', 'block_phone' => null]]]]],
            'hero slide phone block only' => [['type' => 'hero', 'settings' => ['slides' => [['block' => 'BLOCK', 'block_phone' => 'BLOCK']]]]],
            'html block' => [['type' => 'html_block', 'settings' => ['html_block' => 'BLOCK']]],
        ];
    }

    /** @param array<string, mixed> $section */
    #[DataProvider('sections')]
    public function test_saving_a_block_rebuilds_the_published_home_that_shows_it_with_no_menu_or_footer_in_play(array $section): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'B', 'body' => '<p>old</p>']);
        $this->publish(json_decode(str_replace('"BLOCK"', (string) $block->id, json_encode($section)), true));
        $this->assertStringContainsString('old', $this->compiledBlocks()[(string) $block->id] ?? '');

        $block->update(['body' => '<p>new</p>']);
        $this->endOfRequest();

        $this->assertStringContainsString('new', $this->compiledBlocks()[(string) $block->id] ?? '', 'the compiled home still holds the old block');
    }

    public function test_the_answer_is_not_remembered_across_a_home_publish(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'B', 'body' => '<p>old</p>']);

        // asked and answered «nothing shows a block» while no home is published
        $block->update(['body' => '<p>mid</p>']);
        $this->assertFalse(app(MenuRecompiler::class)->hasPending());

        // the home is published NOW, with the block only in an image grid; the same MenuRecompiler instance is still alive
        $this->publish(['type' => 'image_grid', 'settings' => ['cells' => ['a' => $block->id]]]);
        $block->update(['body' => '<p>new</p>']);

        $this->assertTrue(app(MenuRecompiler::class)->hasPending());
        $this->endOfRequest();
        $this->assertStringContainsString('new', $this->compiledBlocks()[(string) $block->id] ?? '');
    }

    public function test_a_block_that_no_published_layout_shows_asks_for_nothing(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'Free', 'body' => '<p>x</p>']);
        $block->update(['body' => '<p>y</p>']);

        $this->assertFalse(app(MenuRecompiler::class)->hasPending());
    }
}
