<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionRedemption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentPromotionRedemptionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): PromotionRedemptionRepository
    {
        return app(PromotionRedemptionRepository::class);
    }

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    private function promotionId(string $code): string
    {
        $promotion = Promotion::create($code, PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 2000);
        app(PromotionRepository::class)->save($promotion);

        return $promotion->id();
    }

    private function orderId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(new SaleLine(
            id: null,
            transactionId: '',
            clientId: $client->id(),
            priceableId: 'variation-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
        ));
        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    private function redeemedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function test_save_then_implicit_count_round_trips(): void
    {
        $promotionId = $this->promotionId('summer20');
        $orderId = $this->orderId();

        $redemption = new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $orderId,
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        );

        $this->repository()->save($redemption);

        $this->assertNotNull($redemption->id());
        $this->assertSame(1, $this->repository()->countForPromotion($promotionId));
    }

    public function test_count_for_promotion_is_correct_after_zero_one_and_two_redemptions(): void
    {
        $promotionId = $this->promotionId('summer20');

        $this->assertSame(0, $this->repository()->countForPromotion($promotionId));

        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        ));
        $this->assertSame(1, $this->repository()->countForPromotion($promotionId));

        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        ));
        $this->assertSame(2, $this->repository()->countForPromotion($promotionId));
    }

    public function test_count_for_promotion_and_account_only_counts_the_matching_account(): void
    {
        $promotionId = $this->promotionId('summer20');
        $accountId = $this->accountId('buyer@example.com');
        $otherAccountId = $this->accountId('other@example.com');

        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: $accountId,
            redeemedAt: $this->redeemedAt(),
        ));
        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: $otherAccountId,
            redeemedAt: $this->redeemedAt(),
        ));

        $this->assertSame(1, $this->repository()->countForPromotionAndAccount($promotionId, $accountId));
        $this->assertSame(1, $this->repository()->countForPromotionAndAccount($promotionId, $otherAccountId));
    }

    public function test_a_guest_redemption_is_never_counted_by_count_for_promotion_and_account(): void
    {
        $promotionId = $this->promotionId('summer20');
        $accountId = $this->accountId('buyer@example.com');

        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        ));

        $this->assertSame(1, $this->repository()->countForPromotion($promotionId));
        $this->assertSame(0, $this->repository()->countForPromotionAndAccount($promotionId, $accountId));
    }

    public function test_the_real_promotion_redemptions_table_foreign_key_delete_rules(): void
    {
        $createTable = DB::select('SHOW CREATE TABLE promotion_redemptions')[0]->{'Create Table'};

        $this->assertStringContainsString(
            'CONSTRAINT `promo_redemptions_promotion_id_foreign` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE RESTRICT',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `promo_redemptions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `promo_redemptions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL',
            $createTable
        );
    }

    public function test_deleting_a_promotion_with_an_existing_redemption_is_rejected_by_the_database(): void
    {
        $promotionId = $this->promotionId('summer20');
        $this->repository()->save(new PromotionRedemption(
            id: null,
            promotionId: $promotionId,
            orderId: $this->orderId(),
            accountId: null,
            redeemedAt: $this->redeemedAt(),
        ));

        $this->expectException(QueryException::class);

        PromotionModel::where('id', $promotionId)->delete();
    }
}
