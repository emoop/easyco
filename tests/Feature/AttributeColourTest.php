<?php

namespace Tests\Feature;

use App\Storefront\Reader\CatalogReader;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Exceptions\InvalidAttributeColourException;
use EasyCo\Catalog\VariationSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * AT-1: a value of an attribute may have a colour (`#rrggbb`, lower-case, nullable) for the round swatches of a «swatch» attribute. The attribute's display type
 * itself exists since CT-3. Data, domain, API and read model; nothing is printed on the storefront.
 */
class AttributeColourTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'packages/EasyCo/Catalog/database/migrations/2026_10_29_000001_add_colour_to_catalog_attribute_values_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        Schema::hasColumn('catalog_attribute_values', 'colour') || $this->migration()->up();

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function reader(): CatalogReader
    {
        $this->app->forgetScopedInstances();

        return app(CatalogReader::class);
    }

    // 1. migration

    public function test_the_column_exists_nullable_and_a_value_that_existed_has_no_colour(): void
    {
        $column = collect(DB::select("show columns from catalog_attribute_values where Field = 'colour'"))->first();

        $this->assertSame('varchar(7)', $column->Type);
        $this->assertSame(['YES', null], [$column->Null, $column->Default]);

        $this->makeAttribute(1, 'Colour', ['Red']);
        $this->assertNull(DB::table('catalog_attribute_values')->where('id', 1001)->value('colour'));
        $this->assertSame('dropdown', DB::table('catalog_attribute_definitions')->where('id', 1)->value('display_type'), 'an existing attribute is a dropdown');
    }

    public function test_down_removes_the_column_up_brings_it_back_and_up_twice_is_harmless(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('catalog_attribute_values', 'colour'));

        $this->migration()->up();
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('catalog_attribute_values', 'colour'));
    }

    // 2. validator

    /** @return array<string, array{string}> */
    public static function badColours(): array
    {
        return [
            '3 digits' => ['#fff'], 'name' => ['red'], 'not hex' => ['#GGGGGG'], '5 digits' => ['#12345'], '7 digits' => ['#1234567'],
            'css injection' => ['1;color:red'], 'no hash' => ['c62828'], 'trailing newline' => ["#c62828\n"], 'space' => ['#c62828 '],
            '600 chars' => [str_repeat('a', 600)], 'quote' => ['#c6282"'],
        ];
    }

    /** @dataProvider badColours */
    #[\PHPUnit\Framework\Attributes\DataProvider('badColours')]
    public function test_a_colour_that_is_not_rrggbb_is_refused(string $bad): void
    {
        $this->expectException(InvalidAttributeColourException::class);

        AttributeValue::normaliseColour($bad);
    }

    public function test_an_upper_case_colour_is_stored_lower_case_and_empty_means_no_colour(): void
    {
        $this->assertSame('#c62828', AttributeValue::normaliseColour('#C62828'));
        $this->assertNull(AttributeValue::normaliseColour(''));
        $this->assertNull(AttributeValue::normaliseColour(null));

        $repository = app(AttributeValueRepository::class);
        $this->makeAttribute(1, 'Colour', []);
        $value = new AttributeValue(null, '1', 'Red', 0, null, '#C62828');
        $repository->save($value);

        $this->assertSame('#c62828', DB::table('catalog_attribute_values')->where('id', $value->id())->value('colour'));

        $value->setColour('');
        $repository->save($value);
        $this->assertNull(DB::table('catalog_attribute_values')->where('id', $value->id())->value('colour'));
    }

    public function test_the_constructor_refuses_a_bad_colour_too(): void
    {
        $this->expectException(InvalidAttributeColourException::class);

        new AttributeValue(null, '1', 'Red', 0, null, '#fff');
    }

    public function test_the_api_refuses_a_bad_colour_and_a_bad_display_type_and_stores_a_good_one_lower_case(): void
    {
        $this->actingAsAdministrator();
        $definition = $this->postJson('/api/attribute-definitions', ['code' => 'colour', 'name' => 'Colour', 'type' => 'select', 'display_type' => 'swatch']);
        $definition->assertCreated()->assertJson(['display_type' => 'swatch']);
        $this->postJson('/api/attribute-definitions', ['code' => 'bad', 'name' => 'Bad', 'type' => 'select', 'display_type' => 'round'])->assertUnprocessable();
        $this->postJson('/api/attribute-definitions', ['code' => 'plain', 'name' => 'Plain', 'type' => 'select'])->assertCreated()->assertJson(['display_type' => 'dropdown']);

        $id = $definition->json('id');

        foreach (['#fff', 'red', '#GGGGGG', '#12345', '1;color:red', str_repeat('#', 600), ['#c62828']] as $bad) {
            $this->postJson('/api/attribute-values', ['attribute_definition_id' => $id, 'value' => 'X', 'colour' => $bad])->assertUnprocessable();
        }

        $this->postJson('/api/attribute-values', ['attribute_definition_id' => $id, 'value' => 'Red', 'colour' => '#C62828'])->assertCreated()->assertJson(['colour' => '#c62828']);
        $this->postJson('/api/attribute-values', ['attribute_definition_id' => $id, 'value' => 'Plain'])->assertCreated()->assertJson(['colour' => null]);
        $this->postJson('/api/attribute-values', ['attribute_definition_id' => $id, 'value' => 'Empty', 'colour' => ''])->assertCreated()->assertJson(['colour' => null]);
        $listed = $this->getJson("/api/attribute-definitions/{$id}/values")->json();
        $this->assertEqualsCanonicalizing(['Red' => '#c62828', 'Plain' => null, 'Empty' => null], array_column($listed, 'colour', 'value'));
    }

    // 3. type switch and the read model

    private function colourShirt(): void
    {
        $this->makeAttribute(1, 'Colour', ['Red', 'Blue', 'Green']);
        DB::table('catalog_attribute_values')->where('id', 1001)->update(['colour' => '#c62828']);
        DB::table('catalog_attribute_values')->where('id', 1002)->update(['colour' => '#1565c0']);
        $this->makeProduct(200, ['type' => 'variable', 'name' => 'Shirt', 'slug' => 'shirt', 'variations' => [
            ['attrs' => [1 => 'Red'], 'price' => '30.00', 'stock' => 2],
            ['attrs' => [1 => 'Blue'], 'price' => '30.00', 'stock' => 2],
            ['attrs' => [1 => 'Green'], 'price' => '30.00', 'stock' => 2],
        ]]);
    }

    public function test_the_read_model_exposes_the_colours_only_while_the_type_is_swatch_and_keeps_them_when_it_comes_back(): void
    {
        $this->seedPricing();
        $this->colourShirt();
        $type = fn (string $t) => DB::table('catalog_attribute_definitions')->where('id', 1)->update(['display_type' => $t]);

        $type('swatch');
        $this->assertSame([['name' => 'Colour', 'displayType' => 'swatch', 'colours' => ['Red' => '#c62828', 'Blue' => '#1565c0', 'Green' => null]]], $this->reader()->product('shirt')->optionDisplay);

        $type('buttons');
        $this->assertSame([['name' => 'Colour', 'displayType' => 'buttons', 'colours' => []]], $this->reader()->product('shirt')->optionDisplay, 'no colour while the type is not swatch');
        $this->assertSame('#c62828', DB::table('catalog_attribute_values')->where('id', 1001)->value('colour'), 'but the stored colour stays');

        $type('swatch');
        $this->assertSame('#c62828', $this->reader()->product('shirt')->optionDisplay[0]['colours']['Red'], 'back to swatch: the colours are still there');
    }

    public function test_a_dropdown_attribute_reports_its_type_and_options_and_the_json_contract_are_unchanged(): void
    {
        $this->seedPricing();
        $this->colourShirt();
        $page = $this->reader()->product('shirt');

        $this->assertSame('dropdown', $page->optionDisplay[0]['displayType']);
        $this->assertSame([['name' => 'Colour', 'values' => ['Red', 'Blue', 'Green']]], $page->options);
        $this->assertArrayNotHasKey('optionDisplay', $page->toArray());
        $this->assertStringNotContainsString('#c62828', json_encode($page->toArray()));
    }

    public function test_the_colour_and_type_cost_no_extra_query_and_an_edit_shows_at_once(): void
    {
        $this->seedPricing();
        $this->colourShirt();
        $count = function (): int {
            $reader = $this->reader();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $reader->product('shirt');

            return count(DB::getQueryLog());
        };

        $plain = $count();
        DB::table('catalog_attribute_definitions')->where('id', 1)->update(['display_type' => 'swatch']);
        DB::table('catalog_attribute_values')->where('id', 1003)->update(['colour' => '#2e7d32']);

        $this->assertSame($plain, $count(), 'the two new facts ride on the query that already loads the values');
        $this->assertSame('#2e7d32', $this->reader()->product('shirt')->optionDisplay[0]['colours']['Green'], 'an edit is visible at once, like a rename (no cache)');
    }

    // 4. identity

    public function test_a_colour_and_a_type_never_change_a_variation_signature_sku_or_slug(): void
    {
        $this->actingAsAdministrator();
        $colour = (string) $this->postJson('/api/attribute-definitions', ['code' => 'colour', 'name' => 'Colour', 'type' => 'select'])->json('id');
        $red = (string) $this->postJson('/api/attribute-values', ['attribute_definition_id' => $colour, 'value' => 'Red'])->json('id');
        $blue = (string) $this->postJson('/api/attribute-values', ['attribute_definition_id' => $colour, 'value' => 'Blue'])->json('id');
        $this->postJson('/api/products/variable', ['name' => 'Shirt', 'base_sku' => 'SHIRT', 'axes' => [['attribute_definition_id' => $colour, 'value_ids' => [$red, $blue]]]])->assertCreated();

        $snapshot = fn () => [
            DB::table('catalog_variations')->orderBy('id')->get(['id', 'sku', 'attribute_signature', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
            DB::table('catalog_products')->orderBy('id')->get(['id', 'slug', 'base_sku', 'updated_at'])->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $snapshot();

        DB::table('catalog_attribute_definitions')->where('id', $colour)->update(['display_type' => 'swatch']);
        $repository = app(AttributeValueRepository::class);

        foreach ([$red => '#c62828', $blue => '#1565c0'] as $id => $hex) {
            $value = $repository->findById((string) $id);
            $value->setColour($hex);
            $repository->save($value);
        }

        $this->assertSame($before, $snapshot(), 'variation rows, SKUs, signatures and slugs are untouched');
        $this->assertContains(VariationSignature::forCombination([$colour => $red])->value(), array_column($before[0], 'attribute_signature'), 'the stored signature is computed from ids only');
        $this->assertContains(VariationSignature::forCombination([$colour => $red])->value(), array_column($snapshot()[0], 'attribute_signature'));
    }
}
