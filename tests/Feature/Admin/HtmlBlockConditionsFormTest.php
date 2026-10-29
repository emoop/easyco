<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Storefront\Conditions\ProductConditions;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * HB-2: the «Показвай, когато…» section of the HTML-block form and its compact layout: what it stores (the normalised JSON), the field errors (the ids are checked on the server; nothing is saved when
 * one is refused), «Без условия», the sections that are closed while empty and open when set, the scope line, and the authorisation (unchanged: `content_manage`).
 */
class HtmlBlockConditionsFormTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeCategory(3, 'Shoes', 'shoes');
        $this->makeTag(1, 'Hot', 'hot');
        $this->makeAttribute(5, 'Size', ['S', 'M']);
        $this->makeAttribute(6, 'Colour', ['Red']);
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Summer', 'slug' => 'summer']);
    }

    /** @param  array<string, mixed>  $conditions */
    private function create(array $conditions, array $style = []): Testable
    {
        return Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Promo', 'body' => '<p>-10%</p>', 'conditions' => $conditions, 'style' => $style])->call('create');
    }

    private function stored(HtmlBlockModel $block): mixed
    {
        $json = DB::table('html_blocks')->where('id', $block->id)->value('conditions');

        return $json === null ? null : json_decode((string) $json, true);
    }

    // ---- saving ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_form_stores_the_normalised_conditions_and_the_block_comes_back_into_the_form(): void
    {
        $this->create(['on_sale' => 'yes', 'categories' => [2, 1], 'tags' => [1], 'seasons' => [1], 'attributes' => [['attribute' => 5, 'values' => [5002, 5001]]]])->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertEquals(['on_sale' => true, 'categories' => [1, 2], 'tags' => [1], 'seasons' => [1], 'attributes' => [['attribute' => 5, 'values' => [5001, 5002]]]], $this->stored($block));

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->assertFormSet(['conditions.on_sale' => 'yes', 'conditions.categories' => [1, 2], 'conditions.tags' => [1], 'conditions.seasons' => [1]]);
    }

    public function test_not_on_sale_is_stored_as_false_not_dropped(): void
    {
        $this->create(['on_sale' => 'no'])->assertHasNoFormErrors();

        $this->assertSame(['on_sale' => false], $this->stored(HtmlBlockModel::query()->firstOrFail()));
        Livewire::test(EditHtmlBlock::class, ['record' => HtmlBlockModel::query()->firstOrFail()->id])->assertFormSet(['conditions.on_sale' => 'no']);
    }

    public function test_a_form_with_nothing_chosen_stores_no_conditions(): void
    {
        $this->create(['on_sale' => 'all', 'categories' => [], 'tags' => [], 'seasons' => [], 'attributes' => []])->assertHasNoFormErrors();

        $this->assertNull($this->stored(HtmlBlockModel::query()->firstOrFail()));
    }

    public function test_the_style_and_the_conditions_save_together_and_independently(): void
    {
        $this->create(['on_sale' => 'yes'], ['bg' => '#2e7d32', 'pad_v' => '6'])->assertHasNoFormErrors();

        $block = HtmlBlockModel::query()->firstOrFail();
        $this->assertSame(['on_sale' => true], $this->stored($block));
        $this->assertEquals(['bg' => '#2e7d32', 'pad_v' => 6], json_decode((string) DB::table('html_blocks')->where('id', $block->id)->value('style'), true));

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->fillForm(['conditions' => ['on_sale' => 'all']])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->stored($block), 'the conditions are gone');
        $this->assertNotNull(DB::table('html_blocks')->where('id', $block->id)->value('style'), 'the style is not touched');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> [the conditions of the form, the field that shows the error] */
    public static function refused(): array
    {
        return [
            'a category that does not exist' => [['categories' => [999]], 'categories'],
            'a tag that does not exist' => [['tags' => [999]], 'tags'],
            'a season that does not exist' => [['seasons' => [999]], 'seasons'],
            'a word instead of an id' => [['categories' => ['abc']], 'categories'],
            'CSS-like text instead of an id' => [['tags' => ['1;2']], 'tags'],
            'a negative id' => [['categories' => [-3]], 'categories'],
            'an id of 600 digits' => [['categories' => [str_repeat('9', 600)]], 'categories'],
            '51 categories' => [['categories' => range(1, 51)], 'categories'],
            'the same attribute twice' => [['attributes' => [['attribute' => 5, 'values' => [5001]], ['attribute' => 5, 'values' => [5002]]]], 'attributes'],
            'a value of another attribute' => [['attributes' => [['attribute' => 5, 'values' => [6001]]]], 'attributes'],
            'an attribute with no value' => [['attributes' => [['attribute' => 5, 'values' => []]]], 'attributes'],
            'a value with no attribute' => [['attributes' => [['attribute' => null, 'values' => [5001]]]], 'attributes'],
            'six attributes' => [['attributes' => array_fill(0, 6, ['attribute' => 5, 'values' => [5001]])], 'attributes'],
        ];
    }

    /**
     * The server is the last line: whatever the browser (or a tampered request) sends is checked again by ProductConditions, and a refusal is a validation error under the field. (Filament's own select
     * already drops a chosen value that is not one of its options; this is what stands behind it.)
     *
     * @param  array<string, mixed>  $conditions
     */
    #[DataProvider('refused')]
    public function test_the_server_refuses_what_does_not_validate_under_the_right_field(array $conditions, string $field): void
    {
        try {
            HtmlBlockResource::conditionsFromForm($conditions);
            $this->fail('a refused value was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.conditions.'.$field, $e->errors());
        }
    }

    public function test_a_refused_attribute_rule_is_a_field_error_on_the_page_and_nothing_is_saved(): void
    {
        foreach ([
            ['attributes' => [['attribute' => 5, 'values' => [5001]], ['attribute' => 5, 'values' => [5002]]]],
            ['attributes' => [['attribute' => 5, 'values' => []]]],
            ['attributes' => array_fill(0, 6, ['attribute' => 5, 'values' => [5001]])],
        ] as $conditions) {
            $this->create($conditions)->assertHasFormErrors(['conditions.attributes']);
        }

        $this->assertSame(0, HtmlBlockModel::query()->count());
    }

    public function test_an_edit_that_is_refused_changes_nothing(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'conditions' => ['on_sale' => true]]);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->fillForm(['title' => 'Renamed', 'conditions' => ['on_sale' => 'yes', 'attributes' => [['attribute' => 5, 'values' => [5001]], ['attribute' => 5, 'values' => [5002]]]]])
            ->call('save')->assertHasFormErrors(['conditions.attributes']);

        $this->assertSame('Promo', $block->fresh()->title);
        $this->assertSame(['on_sale' => true], $this->stored($block));
    }

    public function test_the_messages_are_in_bulgarian_too(): void
    {
        app()->setLocale('bg');

        $this->create(['attributes' => [['attribute' => 5, 'values' => [5001]], ['attribute' => 5, 'values' => [5002]]]])->assertSee('Един атрибут може да се ползва само веднъж.');
        $this->assertSame('Избрано е нещо, което вече не съществува (999). Махнете го.', ProductConditions::validate(['categories' => [999]])[1]['categories']);
    }

    public function test_an_id_that_was_deleted_since_is_dropped_when_the_form_opens(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'conditions' => ['categories' => [3, 2]]]);
        DB::table('catalog_categories')->where('id', 3)->delete();

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->assertFormSet(['conditions.categories' => [2]]);
    }

    // ---- «Без условия», the layout -----------------------------------------------------------------------------------------------------

    public function test_no_conditions_clears_everything(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'conditions' => ['on_sale' => false, 'categories' => [2], 'tags' => [1]]]);

        $page = Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->assertFormSet(['conditions.on_sale' => 'no'])
            ->callAction(TestAction::make('clear_conditions')->schemaComponent('conditions-actions', 'form'))
            ->assertFormSet(['conditions.on_sale' => 'all', 'conditions.categories' => [], 'conditions.tags' => []]);

        $page->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->stored($block));
    }

    public function test_the_page_says_where_the_conditions_apply(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>']);

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->assertSee('Show when…')->assertSee('Applies only in the right column of the product page. In the footer and the menus the block is always shown.');
        app()->setLocale('bg');
        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->assertSee('Показвай, когато…')->assertSee('Важи само в дясната колона на продукта. Във футъра и менютата блокът се показва винаги.');
    }

    public function test_the_two_sections_are_closed_while_empty_and_open_when_they_hold_something(): void
    {
        $plain = HtmlBlockModel::create(['title' => 'Plain', 'body' => '<p>x</p>']);
        $both = HtmlBlockModel::create(['title' => 'Both', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32'], 'conditions' => ['on_sale' => true]]);
        $styled = HtmlBlockModel::create(['title' => 'Styled', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32']]);
        $conditioned = HtmlBlockModel::create(['title' => 'Conditioned', 'body' => '<p>x</p>', 'conditions' => ['tags' => [1]]]);
        $state = fn (HtmlBlockModel $block): array => [
            substr_count(Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->html(), 'isCollapsed: true'),
            substr_count(Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->html(), 'isCollapsed: false'),
        ];

        $this->assertSame([2, 0], $state($plain), 'both closed');
        $this->assertSame([0, 2], $state($both), 'both open');
        $this->assertSame([1, 1], $state($styled), 'only the style is open');
        $this->assertSame([1, 1], $state($conditioned), 'only the conditions are open');
    }

    public function test_the_two_sections_sit_in_one_grid_of_two_columns_that_stacks_on_a_phone(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>']);
        $html = Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->html();

        $this->assertStringContainsString('fi-hb-compact', $html);
        $grid = substr($html, (int) strpos($html, 'fi-hb-compact'));
        $this->assertLessThan(strpos($grid, 'form.show-when'), strpos($grid, 'form.block-style'), 'the style first, then the conditions, inside the same grid');
        $this->assertMatchesRegularExpression('/fi-hb-compact[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/lg:fi-grid-cols|--cols-lg|grid-cols-2|fi-grid/', $grid);
    }

    // ---- authorisation -------------------------------------------------------------------------------------------------------------------

    public function test_the_conditions_form_is_reached_by_the_content_roles_only(): void
    {
        $block = HtmlBlockModel::create(['title' => 'Promo', 'body' => '<p>x</p>', 'conditions' => ['on_sale' => true]]);

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(HtmlBlockResource::getUrl('edit', ['record' => $block]))->assertOk()->assertSee('Show when');
        }

        $this->actingAsStaff('Product Entry');
        $this->get(HtmlBlockResource::getUrl('edit', ['record' => $block]))->assertForbidden();
        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->assertForbidden();
        $this->assertSame(['on_sale' => true], $this->stored($block));
    }
}
