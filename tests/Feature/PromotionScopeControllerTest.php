<?php

namespace Tests\Feature;

use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionScopeControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function promotionId(): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $promotion = Promotion::create("code{$suffix}", PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);

        return $promotion->id();
    }

    private function attachScope(string $promotionId, string $scopeType = 'brand', string $referenceId = 'ref-1', string $mode = 'include'): string
    {
        $scope = new PromotionScope(
            null,
            $promotionId,
            PromotionScopeType::from($scopeType),
            $referenceId,
            PromotionScopeMode::from($mode),
        );
        app(PromotionScopeRepository::class)->attach($scope);

        return $scope->id();
    }

    /** @return array<string, array{0: string}> */
    public static function scopeTypeProvider(): array
    {
        return [
            'brand' => ['brand'],
            'category' => ['category'],
            'tag' => ['tag'],
            'attribute_value' => ['attribute_value'],
            'product' => ['product'],
            'account' => ['account'],
        ];
    }

    #[DataProvider('scopeTypeProvider')]
    public function test_happy_path_attach_for_each_scope_type_returns_201_and_persists(string $scopeType): void
    {
        $promotionId = $this->promotionId();

        $response = $this->postJson("/api/promotions/{$promotionId}/scopes", [
            'scope_type' => $scopeType,
            'scope_reference_id' => 'ref-123',
            'mode' => 'include',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('promotion_id', $promotionId);
        $response->assertJsonPath('scope_type', $scopeType);
        $response->assertJsonPath('scope_reference_id', 'ref-123');
        $response->assertJsonPath('mode', 'include');
        $this->assertNotNull($response->json('id'));

        $this->assertDatabaseHas('promotion_scopes', [
            'id' => $response->json('id'),
            'promotion_id' => $promotionId,
            'scope_type' => $scopeType,
            'scope_reference_id' => 'ref-123',
            'mode' => 'include',
        ]);
    }

    public function test_an_invalid_scope_type_returns_422(): void
    {
        $promotionId = $this->promotionId();

        $response = $this->postJson("/api/promotions/{$promotionId}/scopes", [
            'scope_type' => 'not-a-real-type',
            'scope_reference_id' => 'ref-123',
            'mode' => 'include',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope_type']);
        $this->assertSame(0, PromotionScopeModel::count());
    }

    public function test_an_invalid_mode_returns_422(): void
    {
        $promotionId = $this->promotionId();

        $response = $this->postJson("/api/promotions/{$promotionId}/scopes", [
            'scope_type' => 'brand',
            'scope_reference_id' => 'ref-123',
            'mode' => 'not-a-real-mode',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
        $this->assertSame(0, PromotionScopeModel::count());
    }

    public function test_attaching_to_a_nonexistent_promotion_id_returns_422(): void
    {
        $response = $this->postJson('/api/promotions/999999/scopes', [
            'scope_type' => 'brand',
            'scope_reference_id' => 'ref-123',
            'mode' => 'include',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, PromotionScopeModel::count());
    }

    public function test_attaching_the_same_scope_twice_succeeds_twice_with_no_duplicate_error(): void
    {
        $promotionId = $this->promotionId();
        $payload = ['scope_type' => 'brand', 'scope_reference_id' => 'ref-123', 'mode' => 'include'];

        $first = $this->postJson("/api/promotions/{$promotionId}/scopes", $payload);
        $second = $this->postJson("/api/promotions/{$promotionId}/scopes", $payload);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(2, PromotionScopeModel::where('promotion_id', $promotionId)->count());
    }

    public function test_index_on_a_promotion_with_no_scopes_returns_an_empty_data_array(): void
    {
        $promotionId = $this->promotionId();

        $response = $this->getJson("/api/promotions/{$promotionId}/scopes");

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_index_returns_the_plain_unhydrated_shape(): void
    {
        $promotionId = $this->promotionId();
        $this->attachScope($promotionId, 'brand', 'brand-42', 'exclude');

        $response = $this->getJson("/api/promotions/{$promotionId}/scopes");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame([
            'id' => $data[0]['id'],
            'promotion_id' => $promotionId,
            'scope_type' => 'brand',
            'scope_reference_id' => 'brand-42',
            'mode' => 'exclude',
        ], $data[0]);
    }

    public function test_index_for_a_nonexistent_promotion_id_returns_422(): void
    {
        $response = $this->getJson('/api/promotions/999999/scopes');

        $response->assertStatus(422);
    }

    public function test_destroy_happy_path_returns_204_and_removes_the_row(): void
    {
        $promotionId = $this->promotionId();
        $scopeId = $this->attachScope($promotionId);

        $response = $this->deleteJson("/api/promotions/{$promotionId}/scopes/{$scopeId}");

        $response->assertStatus(204);
        $this->assertSame(0, PromotionScopeModel::count());
    }

    public function test_destroy_a_scope_belonging_to_a_different_promotion_returns_404_and_does_not_delete(): void
    {
        $promotionId = $this->promotionId();
        $otherPromotionId = $this->promotionId();
        $scopeId = $this->attachScope($promotionId);

        $response = $this->deleteJson("/api/promotions/{$otherPromotionId}/scopes/{$scopeId}");

        $response->assertStatus(404);
        $this->assertSame(1, PromotionScopeModel::count());
    }

    public function test_destroy_a_nonexistent_scope_id_on_a_real_promotion_returns_404(): void
    {
        $promotionId = $this->promotionId();

        $response = $this->deleteJson("/api/promotions/{$promotionId}/scopes/999999");

        $response->assertStatus(404);
    }
}
