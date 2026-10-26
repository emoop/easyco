<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PC-2 (endpoint): GET /storefront/card-sizes?ids= answers the SIZES of a listing page's cards in ONE batch — the values of the product's first `buttons`
 * axis (CT-3), in the value order, sold-out ones marked — for visible variable products only. Sessionless, cacheable for 60 s, never a cookie, a fixed number of
 * queries, a 422 with an empty body for anything that is not a valid id list, and a 60-per-minute limit.
 */
class CardSizesTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private const SIZE = 1;
    private const COLOUR = 2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeAttribute(self::SIZE, 'Size', ['S', 'M', 'L']);
        $this->makeAttribute(self::COLOUR, 'Colour', ['Red']);
        DB::table('catalog_attribute_definitions')->where('id', self::SIZE)->update(['display_type' => 'buttons']);
        DB::table('catalog_attribute_definitions')->where('id', self::COLOUR)->update(['display_type' => 'swatch']);
        Cache::flush();
    }

    /** A variable product whose size axis is declared with the given variations (size value => stock). */
    private function dressWith(int $id, array $stockBySize, array $extra = []): void
    {
        $variations = [];

        foreach ($stockBySize as $size => $stock) {
            $variations[] = ['attrs' => [self::SIZE => $size], 'stock' => $stock];
        }

        $this->makeProduct($id, $extra + ['type' => 'variable', 'name' => "Dress {$id}", 'slug' => "dress-{$id}", 'variations' => $variations, 'categories' => []]);
        $this->declareAxis($id, self::SIZE, 0);
    }

    private function declareAxis(int $productId, int $definitionId, int $position): void
    {
        DB::table('catalog_product_attributes')->insert([
            'product_id' => $productId, 'attribute_definition_id' => $definitionId, 'is_variation_axis' => true, 'sort_order' => $position,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    private const STAMP = '2026-01-01 10:00:00';

    /** @return \Illuminate\Testing\TestResponse */
    private function sizes(string $ids)
    {
        $this->app->forgetScopedInstances();

        return $this->get('/storefront/card-sizes?ids='.$ids);
    }

    public function test_the_sizes_are_the_size_values_in_value_order_and_a_sold_out_one_is_marked(): void
    {
        $this->dressWith(10, ['S' => 2, 'M' => 0, 'L' => 5]);

        $this->sizes('10')->assertOk()->assertExactJson(['sizes' => ['10' => [
            ['label' => 'S', 'in_stock' => true],
            ['label' => 'M', 'in_stock' => false],
            ['label' => 'L', 'in_stock' => true],
        ]]]);
    }

    public function test_a_size_with_stock_only_on_a_hidden_variation_is_sold_out(): void
    {
        $this->makeProduct(11, ['type' => 'variable', 'name' => 'Dress 11', 'slug' => 'dress-11', 'variations' => [
            ['attrs' => [self::SIZE => 'M'], 'stock' => 4, 'visible' => false],
            ['attrs' => [self::SIZE => 'M'], 'stock' => 0],
        ]]);
        $this->declareAxis(11, self::SIZE, 0);

        $this->sizes('11')->assertOk()->assertExactJson(['sizes' => ['11' => [['label' => 'M', 'in_stock' => false]]]]);
    }

    public function test_only_the_first_buttons_axis_is_used_and_a_colour_swatch_is_never_a_size(): void
    {
        $this->makeProduct(12, ['type' => 'variable', 'name' => 'Shirt 12', 'slug' => 'shirt-12', 'variations' => [
            ['attrs' => [self::COLOUR => 'Red', self::SIZE => 'S'], 'stock' => 1],
        ]]);
        $this->declareAxis(12, self::COLOUR, 0);
        $this->declareAxis(12, self::SIZE, 1);

        $body = $this->sizes('12')->assertOk()->json();

        $this->assertSame([['label' => 'S', 'in_stock' => true]], $body['sizes']['12']);
    }

    public function test_a_product_with_no_buttons_axis_simple_unknown_hidden_or_draft_is_absent(): void
    {
        $this->makeProduct(13, ['type' => 'variable', 'name' => 'Only colour', 'slug' => 'only-colour', 'variations' => [
            ['attrs' => [self::COLOUR => 'Red'], 'stock' => 1],
        ]]);
        $this->declareAxis(13, self::COLOUR, 0);
        $this->makeProduct(14, ['name' => 'Simple', 'slug' => 'simple-14', 'stock' => 3]);
        $this->dressWith(15, ['M' => 1], ['visibility' => 'hidden']);
        $this->dressWith(16, ['M' => 1], ['status' => 'draft']);

        $body = $this->sizes('13,14,15,16,99999')->assertOk()->json();

        $this->assertSame(['sizes' => []], $body);
    }

    public function test_at_most_eight_sizes_come_back_in_the_value_order(): void
    {
        $sizes = [];

        for ($i = 1; $i <= 9; $i++) {
            DB::table('catalog_attribute_values')->insert(['id' => 5000 + $i,'attribute_definition_id' => self::SIZE, 'value' => "N{$i}", 'sort_order' => 100 + $i, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
            $sizes["N{$i}"] = 1;
        }

        $this->dressWith(17, $sizes);

        $list = $this->sizes('17')->assertOk()->json('sizes.17');

        $this->assertCount(8, $list);
        $this->assertSame('N1', $list[0]['label']);
        $this->assertSame('N8', $list[7]['label']);
    }

    public function test_duplicate_ids_are_asked_once_and_answered_once(): void
    {
        $this->dressWith(18, ['S' => 1]);

        $this->sizes('18,18,18')->assertOk()->assertExactJson(['sizes' => ['18' => [['label' => 'S', 'in_stock' => true]]]]);
    }

    /** @return array<string, array{0: string}> */
    public static function badIds(): array
    {
        $many = implode(',', range(1, 49));

        return [
            'missing' => [''], 'no key' => ['__none__'], 'letters' => ['abc'], 'negative' => ['-1'], 'zero' => ['0'], 'leading zero' => ['07'],
            'float' => ['1.5'], 'space' => ['1, 2'], 'trailing comma' => ['1,'], 'too many' => [$many],
        ];
    }

    #[DataProvider('badIds')]
    public function test_an_invalid_id_list_answers_422_with_an_empty_body(string $ids): void
    {
        $url = $ids === '__none__' ? '/storefront/card-sizes' : '/storefront/card-sizes?ids='.urlencode($ids);

        $response = $this->get($url)->assertStatus(422);

        $this->assertSame('{}', $response->getContent());
    }

    public function test_an_array_form_of_the_ids_is_refused(): void
    {
        $this->get('/storefront/card-sizes?ids[]=1&ids[]=2')->assertStatus(422)->assertSee('{}', false);
    }

    public function test_the_answer_is_cacheable_for_sixty_seconds_and_sets_no_cookie(): void
    {
        $this->dressWith(19, ['S' => 1]);

        $response = $this->sizes('19')->assertOk();

        // the directives, whatever order the framework writes them in: public, max-age=60, s-maxage=60
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);
        $this->assertSame(['max-age=60', 'public', 's-maxage=60'], $directives);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Set-Cookie'), 'no cookie can leave a cacheable answer');
        $this->assertStringNotContainsString('Cookie', (string) $response->headers->get('Vary'));
    }

    public function test_the_query_count_is_the_same_for_one_product_and_for_forty_eight(): void
    {
        $this->dressWith(20, ['S' => 1]);
        $this->sizes('20')->assertOk(); // warm-up

        $one = $this->countQueries(fn () => $this->sizes('20'));

        $ids = [];

        for ($id = 21; $id <= 68; $id++) {
            $this->dressWith($id, ['S' => 1, 'M' => 0]);
            $ids[] = $id;
        }

        $this->sizes(implode(',', $ids))->assertOk(); // warm-up
        $many = $this->countQueries(fn () => $this->sizes(implode(',', $ids)));

        $this->assertSame($one, $many, 'one batch, however many products are asked about');
    }

    public function test_more_than_sixty_requests_a_minute_are_refused_with_429(): void
    {
        $this->dressWith(70, ['S' => 1]);

        for ($i = 0; $i < 60; $i++) {
            $this->sizes('70')->assertOk();
        }

        $this->sizes('70')->assertStatus(429);
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
