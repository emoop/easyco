<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HB-1: the «Стил на блока» section of the HTML-block form: what it stores (the normalised JSON), the field errors (nothing is saved when a value is refused), «Без стил», the contrast warning, the
 * live preview, the cap of a hundred styled blocks and the authorisation (unchanged: `content_manage`).
 */
class HtmlBlockStyleFormTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    /** @param  array<string, mixed>  $style */
    private function create(array $style): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Autumn promo', 'body' => '<p>-10%</p>', 'style' => $style])->call('create');
    }

    private function stored(HtmlBlockModel $block): mixed
    {
        $json = DB::table('html_blocks')->where('id', $block->id)->value('style');

        return $json === null ? null : json_decode((string) $json, true);
    }

    public function test_the_form_stores_the_normalised_style_and_the_block_comes_back_into_the_form(): void
    {
        $this->create(['bg' => '#2E7D32', 'color' => '#ffffff', 'pad_v' => '6', 'pad_h' => '10', 'radius' => '4', 'width_mode' => 'auto', 'align' => 'center', 'size' => 'normal', 'border_w' => '', 'width_px' => ''])
            ->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertEquals(['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'radius' => 4, 'align' => 'center'], $this->stored($block));

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->assertFormSet(['style.bg' => '#2e7d32', 'style.pad_v' => '6', 'style.pad_h' => '10', 'style.radius' => '4', 'style.align' => 'center', 'style.border_w' => '']);
    }

    public function test_a_form_with_no_style_stores_no_style(): void
    {
        $this->create(['bg' => null, 'pad_v' => '', 'width_mode' => 'auto', 'align' => 'left'])->assertHasNoFormErrors();

        $this->assertNull($this->stored(HtmlBlockModel::query()->firstOrFail()));
    }

    public function test_a_fixed_width_stores_its_width(): void
    {
        $this->create(['bg' => '#112233', 'width_mode' => 'fixed', 'width_px' => '320'])->assertHasNoFormErrors();

        $this->assertEquals(['bg' => '#112233', 'width_mode' => 'fixed', 'width_px' => 320], $this->stored(HtmlBlockModel::query()->firstOrFail()));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> [the style of the form, the field that shows the error] */
    public static function refused(): array
    {
        return [
            'a padding over 64' => [['pad_v' => '65'], 'pad_v'],
            'CSS behind a number' => [['pad_h' => '1;color:red'], 'pad_h'],
            'a border of 5' => [['border_w' => '5'], 'border_w'],
            'a radius of 33' => [['radius' => '33'], 'radius'],
            'a colour that is a name' => [['bg' => 'red'], 'bg'],
            'a short colour' => [['color' => '#fff'], 'color'],
            'a bad border colour' => [['border_color' => '#GGGGGG'], 'border_color'],
            'a fixed width with no width' => [['width_mode' => 'fixed', 'width_px' => ''], 'width_px'],
            'a fixed width that is too small' => [['width_mode' => 'fixed', 'width_px' => '50'], 'width_px'],
        ];
    }

    /** @param  array<string, mixed>  $style */
    #[DataProvider('refused')]
    public function test_a_refused_value_is_a_field_error_and_nothing_is_saved(array $style, string $field): void
    {
        $this->create($style)->assertHasFormErrors(['style.'.$field]);

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_an_edit_that_is_refused_changes_nothing(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32']]);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->fillForm(['title' => 'Renamed', 'style' => ['bg' => 'red']])->call('save')->assertHasFormErrors(['style.bg']);

        $this->assertSame('Promo', $block->fresh()->title);
        $this->assertEquals(['bg' => '#2e7d32'], $this->stored($block));
    }

    public function test_the_messages_are_in_bulgarian_too(): void
    {
        app()->setLocale('bg');

        $this->create(['pad_v' => '65'])->assertSee('Цяло число от 0 до 64.');
    }

    public function test_no_style_clears_everything(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32', 'pad_v' => 6, 'align' => 'center']]);

        $page = Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->assertFormSet(['style.bg' => '#2e7d32'])
            ->callAction(TestAction::make('clear_style')->schemaComponent('style-actions', 'form'))
            ->assertFormSet(['style.bg' => null, 'style.pad_v' => '', 'style.align' => 'left', 'style.width_mode' => 'auto']);

        $page->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->stored($block));
    }

    public function test_a_colour_can_be_cleared_with_its_none_button(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32', 'color' => '#ffffff']]);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->callAction(TestAction::make('clear_bg')->schemaComponent('style.bg', 'form'))
            ->assertFormSet(['style.bg' => null, 'style.color' => '#ffffff']);
    }

    public function test_the_contrast_warning_shows_only_when_both_colours_are_set_and_too_close(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>']);
        $page = fn (array $style) => Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->fillForm(['style' => $style]);

        $page(['bg' => '#ffffff', 'color' => '#f5f5f5'])->assertSee('Low contrast (1.09:1)');
        $page(['bg' => '#ffffff', 'color' => '#000000'])->assertDontSee('Low contrast');
        $page(['bg' => '#ffffff', 'color' => null])->assertDontSee('Low contrast');
        $page(['bg' => '#ffffff', 'color' => 'nonsense'])->assertDontSee('Low contrast');
    }

    public function test_the_live_preview_shows_the_title_with_the_valid_values_only(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Autumn promo', 'body' => '<p>x</p>']);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->fillForm(['style' => ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => '6', 'pad_h' => '10']])
            ->assertSee('Autumn promo')
            ->assertSeeHtml('background:#2e7d32;color:#ffffff;padding:6px 10px;')
            ->fillForm(['style' => ['bg' => '#2e7d32', 'pad_v' => '999']])
            ->assertSeeHtml('background:#2e7d32;')
            ->assertDontSeeHtml('padding:999px');
    }

    public function test_the_preview_is_the_only_inline_style_and_it_is_built_from_validated_values(): void
    {
        $this->assertSame('background:#2e7d32;padding:6px 10px;width:fit-content;max-width:100%', HtmlBlockStyle::previewDeclarations(['bg' => '#2E7D32', 'color' => '#fff', 'pad_v' => '6', 'pad_h' => 10]), 'the colour that is not #rrggbb is left out');
        $this->assertSame('background:#2e7d32;width:fit-content;max-width:100%', HtmlBlockStyle::previewDeclarations(['bg' => '#2e7d32', 'color' => '#fff', 'pad_v' => 'x;y']), 'the invalid fields are left out');
        $this->assertSame('', HtmlBlockStyle::previewDeclarations(['width_mode' => 'fixed']), 'a fixed width with no width shows nothing');
        $this->assertSame('', HtmlBlockStyle::previewDeclarations(null));
        $this->assertSame('', HtmlBlockStyle::previewDeclarations('red'));
        $this->assertStringNotContainsString('url(', HtmlBlockStyle::previewDeclarations(['bg' => 'url(x)', 'color' => '#112233']));
    }

    // ---- the cap -------------------------------------------------------------------------------------------------------------------

    public function test_the_101st_styled_block_is_refused_in_bulgarian_and_unstyling_one_allows_it(): void
    {
        $rows = [];

        foreach (range(1, HtmlBlockStyle::MAX_STYLED) as $n) {
            $rows[] = ['id' => $n, 'title' => 'B'.$n, 'body' => '<p>x</p>', 'style' => json_encode(['bg' => '#112233']), 'created_at' => now(), 'updated_at' => now()];
        }

        DB::table('html_blocks')->insert($rows);
        app()->setLocale('bg');

        $this->create(['bg' => '#2e7d32'])->assertHasFormErrors(['style.bg'])->assertSee('Най-много 100 блока могат да имат стил. Махнете стила на друг блок.');
        $this->assertSame(100, HtmlBlockModel::query()->count());

        // a block with no style can still be created
        $this->create(['bg' => null])->assertHasNoFormErrors();
        $this->assertSame(101, HtmlBlockModel::query()->count());

        // one of the hundred may be edited
        Livewire::test(EditHtmlBlock::class, ['record' => 5])->fillForm(['style' => ['bg' => '#445566']])->call('save')->assertHasNoFormErrors();

        // unstyling one makes room
        DB::table('html_blocks')->where('id', 7)->update(['style' => null]);
        $this->create(['bg' => '#2e7d32'])->assertHasNoFormErrors();
        $this->assertSame(102, HtmlBlockModel::query()->count());
    }

    // ---- authorisation -------------------------------------------------------------------------------------------------------------

    public function test_the_style_form_is_reached_by_the_content_roles_only(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32']]);

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(HtmlBlockResource::getUrl('edit', ['record' => $block]))->assertOk()->assertSee('Block style');
        }

        $this->actingAsStaff('Product Entry');
        $this->get(HtmlBlockResource::getUrl('edit', ['record' => $block]))->assertForbidden();
        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->assertForbidden();
        $this->assertEquals(['bg' => '#2e7d32'], $this->stored($block));
    }
}
