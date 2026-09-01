<?php

namespace Tests\Feature;

use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionControllerTest extends TestCase
{
    use RefreshDatabase;

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
}
