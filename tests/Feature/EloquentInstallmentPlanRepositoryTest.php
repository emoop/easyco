<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Contracts\InstallmentPlanRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\InstallmentPlanStatus;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Exceptions\ClientAlreadyHasActiveInstallmentPlanException;
use EasyCo\OperationalSales\InstallmentPlan;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\Persistence\Eloquent\InstallmentPlanModel;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EloquentInstallmentPlanRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): InstallmentPlanRepository
    {
        return app(InstallmentPlanRepository::class);
    }

    private function transactionRepository(): TransactionRepository
    {
        return app(TransactionRepository::class);
    }

    private function clientId(): string
    {
        return (string) ClientModel::create(['name' => 'Test Client'])->id;
    }

    private function money(int $minorUnits, string $currency = 'EUR'): Money
    {
        return Money::fromMinorUnits($minorUnits, $currency);
    }

    /**
     * Builds and persists (via TransactionRepository, satisfying
     * sale_lines.transaction_id's NOT NULL constraint) one RESERVATION
     * SaleLine for the given client, returning the real, persisted
     * SaleLine domain object.
     */
    private function persistedReservedLine(string $clientId, int $amountMinorUnits): SaleLine
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $line = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::RESERVATION,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money($amountMinorUnits),
            profit: $this->money((int) round($amountMinorUnits * 0.2)),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable('2020-01-01 00:00:00'),
        );
        $transaction->addSaleLine($line);
        $this->transactionRepository()->save($transaction);

        return $line;
    }

    private function persistedPaymentLine(string $clientId, int $amountMinorUnits): SaleLine
    {
        $transaction = new Transaction(id: null, channel: Channel::POS);
        $line = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: null,
            type: SaleLineType::INSTALLMENT_PAYMENT,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: $this->money($amountMinorUnits),
            profit: $this->money(0),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
        );
        $transaction->addSaleLine($line);
        $this->transactionRepository()->save($transaction);

        return $line;
    }

    public function test_save_links_reserved_and_payment_lines_to_the_plan_and_persists_status(): void
    {
        $clientId = $this->clientId();
        $reserved = $this->persistedReservedLine($clientId, 1000);

        $plan = InstallmentPlan::open($clientId);
        $plan->attachReservedLine($reserved);
        $plan->recordPayment($paymentLineBuilder = $this->persistedPaymentLine($clientId, 400));

        $this->repository()->save($plan);

        $this->assertNotNull($plan->id());
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $plan->status());

        $this->assertSame((int) $plan->id(), SaleLineModel::find($reserved->id())->installment_plan_id);
        $this->assertSame((int) $plan->id(), SaleLineModel::find($paymentLineBuilder->id())->installment_plan_id);
    }

    public function test_find_by_id_reloads_reserved_and_payment_lines_as_real_sale_lines(): void
    {
        $clientId = $this->clientId();
        $reserved = $this->persistedReservedLine($clientId, 1000);

        $plan = InstallmentPlan::open($clientId);
        $plan->attachReservedLine($reserved);
        $plan->recordPayment($this->persistedPaymentLine($clientId, 400));
        $this->repository()->save($plan);

        $reloaded = $this->repository()->findById($plan->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($plan->id(), $reloaded->id());
        $this->assertSame($clientId, $reloaded->clientId());
        $this->assertSame(InstallmentPlanStatus::ACTIVE, $reloaded->status());

        $this->assertCount(1, $reloaded->reservedLines());
        $reloadedReserved = $reloaded->reservedLines()[0];
        $this->assertSame($reserved->id(), $reloadedReserved->id());
        $this->assertSame(SaleLineType::RESERVATION, $reloadedReserved->type());
        $this->assertNull($reloadedReserved->originatingReservationLineId());

        $this->assertCount(1, $reloaded->paymentLines());
        $this->assertSame(SaleLineType::INSTALLMENT_PAYMENT, $reloaded->paymentLines()[0]->type());

        $this->assertTrue($reloaded->outstandingBalance()->equals($this->money(600)));
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_id(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_active_by_client_id_returns_the_active_plan(): void
    {
        $clientId = $this->clientId();
        $reserved = $this->persistedReservedLine($clientId, 1000);

        $plan = InstallmentPlan::open($clientId);
        $plan->attachReservedLine($reserved);
        $this->repository()->save($plan);

        $found = $this->repository()->findActiveByClientId($clientId);

        $this->assertNotNull($found);
        $this->assertSame($plan->id(), $found->id());
    }

    public function test_find_active_by_client_id_returns_null_when_the_client_has_no_active_plan(): void
    {
        $clientId = $this->clientId();

        $this->assertNull($this->repository()->findActiveByClientId($clientId));
    }

    public function test_find_active_by_client_id_returns_null_once_the_only_plan_is_cancelled(): void
    {
        $clientId = $this->clientId();

        $plan = InstallmentPlan::open($clientId);
        $plan->cancel();
        $this->repository()->save($plan);

        $this->assertNull($this->repository()->findActiveByClientId($clientId));
    }

    /**
     * Previously this test proved findActiveByClientId()'s documented
     * fallback behavior for "more than one ACTIVE plan exists" (a
     * flagged, then-unenforced gap). That scenario is now structurally
     * unreachable through save() — the active_client_id UNIQUE
     * constraint added in this pass refuses to let a second ACTIVE plan
     * for the same client ever be persisted at all (see the tests
     * below), so there is nothing left for this test to prove. Removed
     * rather than kept as dead/impossible-to-trigger coverage.
     */
    public function test_second_save_for_the_same_client_throws_while_the_first_succeeds(): void
    {
        $clientId = $this->clientId();

        $firstPlan = InstallmentPlan::open($clientId);
        $this->repository()->save($firstPlan);
        $this->assertNotNull($firstPlan->id());

        $secondPlan = InstallmentPlan::open($clientId);

        $this->expectException(ClientAlreadyHasActiveInstallmentPlanException::class);
        $this->repository()->save($secondPlan);
    }

    /**
     * The genuine concurrent-race proof, mirroring
     * DatabaseUniquenessConstraintTest::test_concurrent_insert_race_is_caught_by_the_constraint_not_a_check_then_insert
     * in spirit: two independent in-memory InstallmentPlan objects for
     * the same client (simulating two operators racing to open a plan),
     * neither aware of the other. EloquentInstallmentPlanRepository::
     * save() has no SELECT-then-INSERT pre-check anywhere — it attempts
     * the write directly and lets the UNIQUE(active_client_id)
     * constraint decide. Confirms the second failure is a genuine
     * QueryException surfacing from the database (via getPrevious()),
     * not an app-layer guard, and that the losing plan is left with no
     * id at all — no partial write survives the transaction rollback.
     */
    public function test_concurrent_save_race_is_caught_by_the_db_constraint_not_a_check_then_insert(): void
    {
        $clientId = $this->clientId();

        $firstPlan = InstallmentPlan::open($clientId);
        $secondPlan = InstallmentPlan::open($clientId);

        $this->repository()->save($firstPlan);

        try {
            $this->repository()->save($secondPlan);
            $this->fail('Expected ClientAlreadyHasActiveInstallmentPlanException to be thrown.');
        } catch (ClientAlreadyHasActiveInstallmentPlanException $e) {
            $this->assertInstanceOf(
                QueryException::class,
                $e->getPrevious(),
                'the DB constraint, not application logic, must be what stops the race'
            );
        }

        $this->assertNull($secondPlan->id(), 'no partial write should survive the rolled-back transaction');
    }

    public function test_completing_a_plan_frees_the_client_to_open_a_new_active_plan(): void
    {
        $clientId = $this->clientId();
        $reserved = $this->persistedReservedLine($clientId, 1000);

        $plan = InstallmentPlan::open($clientId);
        $plan->attachReservedLine($reserved);
        $plan->recordPayment($this->persistedPaymentLine($clientId, 1000)); // exact payoff -> COMPLETED
        $this->assertSame(InstallmentPlanStatus::COMPLETED, $plan->status());

        $this->repository()->save($plan);

        $this->assertNull(InstallmentPlanModel::find($plan->id())->active_client_id);
        $this->assertNull($this->repository()->findActiveByClientId($clientId));

        $newPlan = InstallmentPlan::open($clientId);
        $this->repository()->save($newPlan);

        $this->assertNotNull($newPlan->id());
        $this->assertSame($newPlan->id(), $this->repository()->findActiveByClientId($clientId)->id());
    }

    public function test_cancelling_a_plan_frees_the_client_to_open_a_new_active_plan(): void
    {
        $clientId = $this->clientId();

        $plan = InstallmentPlan::open($clientId);
        $this->repository()->save($plan);

        $plan->cancel();
        $this->repository()->save($plan);

        $this->assertNull(InstallmentPlanModel::find($plan->id())->active_client_id);
        $this->assertNull($this->repository()->findActiveByClientId($clientId));

        $newPlan = InstallmentPlan::open($clientId);
        $this->repository()->save($newPlan);

        $this->assertNotNull($newPlan->id());
        $this->assertSame($newPlan->id(), $this->repository()->findActiveByClientId($clientId)->id());
    }

    /**
     * The real ordering constraint documented on
     * EloquentInstallmentPlanRepository's class docblock: a SaleLine
     * must already be persisted (via its own Transaction) before the
     * plan referencing it is saved.
     */
    public function test_save_throws_when_a_reserved_line_has_never_been_persisted(): void
    {
        $clientId = $this->clientId();

        $unpersistedReserved = new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'priceable-1',
            type: SaleLineType::RESERVATION,
            status: SaleLineStatus::PENDING,
            quantity: 1,
            amount: $this->money(1000),
            profit: $this->money(200),
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
        );

        $plan = InstallmentPlan::open($clientId);
        $plan->attachReservedLine($unpersistedReserved);

        $this->expectException(RuntimeException::class);
        $this->repository()->save($plan);
    }
}
