<?php

namespace Tests\Feature;

use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdministrator();
    }

    public function test_happy_path_store_for_a_percentage_promotion_returns_201_and_persists(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => 'SUMMER20',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'code' => 'summer20',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
            'discount_amount' => null,
            'individual_use_only' => false,
            'exclude_sale_items' => false,
            'new_customers_only' => false,
            'status' => 'active',
        ]);
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('promotions', [
            'id' => $response->json('id'),
            'code' => 'summer20',
            'discount_type' => 'percentage',
            'discount_percentage_basis_points' => 2000,
            'discount_amount_minor' => null,
        ]);
    }

    public function test_happy_path_store_for_a_fixed_amount_promotion_round_trips_the_money_correctly(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => 'TENOFF',
            'discount_type' => 'fixed_amount',
            'discount_amount' => '10.00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('discount_amount.amount', '10.00');
        $response->assertJsonPath('discount_amount.currency', 'EUR');
        $response->assertJsonPath('percentage_basis_points', null);

        $this->assertDatabaseHas('promotions', [
            'id' => $response->json('id'),
            'code' => 'tenoff',
            'discount_type' => 'fixed_amount',
            'discount_amount_minor' => 1000,
            'discount_amount_currency' => 'EUR',
            'discount_percentage_basis_points' => null,
        ]);
    }

    public function test_a_missing_code_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/promotions', [
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PromotionModel::count());
    }

    public function test_percentage_basis_points_supplied_when_discount_type_is_fixed_amount_returns_422(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => 'BADCOMBO',
            'discount_type' => 'fixed_amount',
            'discount_amount' => '10.00',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['percentage_basis_points']);
        $this->assertSame(0, PromotionModel::count());
    }

    public function test_discount_amount_supplied_when_discount_type_is_percentage_returns_422(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => 'BADCOMBO2',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
            'discount_amount' => '10.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discount_amount']);
        $this->assertSame(0, PromotionModel::count());
    }

    public function test_a_duplicate_code_returns_a_clean_422_not_a_500(): void
    {
        $first = $this->postJson('/api/promotions', [
            'code' => 'DUPCODE',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 1000,
        ]);
        $first->assertStatus(201);

        $second = $this->postJson('/api/promotions', [
            'code' => 'dupcode',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 500,
        ]);

        $second->assertStatus(422);
        $this->assertSame(1, PromotionModel::count());
    }

    public function test_index_returns_everything_created_with_the_correct_shape_for_both_discount_types(): void
    {
        $this->postJson('/api/promotions', [
            'code' => 'SUMMER20',
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ])->assertStatus(201);

        $this->postJson('/api/promotions', [
            'code' => 'TENOFF',
            'discount_type' => 'fixed_amount',
            'discount_amount' => '10.00',
        ])->assertStatus(201);

        $response = $this->getJson('/api/promotions');

        $response->assertStatus(200);
        $response->assertJsonCount(2);

        $byCode = collect($response->json())->keyBy('code');

        $this->assertSame(2000, $byCode['summer20']['percentage_basis_points']);
        $this->assertNull($byCode['summer20']['discount_amount']);

        $this->assertNull($byCode['tenoff']['percentage_basis_points']);
        $this->assertSame('10.00', $byCode['tenoff']['discount_amount']['amount']);
        $this->assertSame('EUR', $byCode['tenoff']['discount_amount']['currency']);
    }

    // --- Input hardening: the code and the three amounts (input-hardening pass 1) -----

    public function test_a_256_character_code_is_a_422_field_error_and_writes_nothing(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => str_repeat('я', 256),
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PromotionModel::count());
    }

    public function test_a_255_character_cyrillic_code_is_accepted_and_stored_unchanged(): void
    {
        $code = str_repeat('я', 255);

        $response = $this->postJson('/api/promotions', [
            'code' => $code,
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('code', $code);
        $this->assertDatabaseHas('promotions', ['code' => $code]);
    }

    /** @return array<string, array{string}> */
    public static function refusedCodeValues(): array
    {
        return [
            'a newline' => ["SUMMER\n20"],
            'a NUL byte' => ["SUMMER\020"],
            'a tab' => ["SUMMER\t20"],
            'a right-to-left override' => ['SUMMER'."\u{202E}".'20'],
        ];
    }

    #[DataProvider('refusedCodeValues')]
    public function test_a_code_carrying_a_control_or_bidirectional_character_is_a_422_and_writes_nothing(string $code): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => $code,
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
        $this->assertSame(0, PromotionModel::count());
    }

    /** Special characters are legitimate in a code and are never stripped. */
    public function test_a_code_with_special_characters_is_accepted_and_stored_unchanged(): void
    {
        $code = "o'brien & sons <ltd>";

        $response = $this->postJson('/api/promotions', [
            'code' => $code,
            'discount_type' => 'percentage',
            'percentage_basis_points' => 2000,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('code', $code);
        $this->assertDatabaseHas('promotions', ['code' => $code]);
    }

    /**
     * Everything that used to reach Money::fromDecimal() as something it cannot
     * parse — the 500s — plus an amount with more decimals than EUR has, which
     * Money would silently round half-up. Each one is checked on all three
     * amount fields, and nothing may be written.
     *
     * @return array<string, array{string}>
     */
    public static function refusedAmounts(): array
    {
        return [
            'a 30-digit amount' => [str_repeat('9', 30)],
            'scientific notation' => ['1e5'],
            'a negative amount' => ['-1'],
            'a comma decimal' => ['1,5'],
            'more decimals than EUR has' => ['10.999'],
            'a trailing decimal point' => ['10.'],
            'an internal space' => ['1 0'],
            'a thousands separator' => ['1.000'],
        ];
    }

    #[DataProvider('refusedAmounts')]
    public function test_a_non_plain_amount_is_a_422_field_error_on_every_amount_field_and_writes_nothing(string $amount): void
    {
        foreach (['discount_amount', 'minimum_spend', 'maximum_spend'] as $field) {
            $payload = [
                'code' => 'PLAINAMOUNT',
                'discount_type' => 'fixed_amount',
                'discount_amount' => '10.00',
                $field => $amount,
            ];

            $response = $this->postJson('/api/promotions', $payload);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, PromotionModel::count());
    }

    public function test_plain_amounts_with_the_currencys_own_precision_are_accepted(): void
    {
        $response = $this->postJson('/api/promotions', [
            'code' => 'NINETYNINE',
            'discount_type' => 'fixed_amount',
            'discount_amount' => '99.99',
            'minimum_spend' => '10.5',
            'maximum_spend' => '1000',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('discount_amount.amount', '99.99');

        $this->assertDatabaseHas('promotions', [
            'id' => $response->json('id'),
            'code' => 'ninetynine',
            'discount_amount_minor' => 9999,
            'minimum_spend_amount_minor' => 1050,
            'maximum_spend_amount_minor' => 100000,
        ]);
    }
}
