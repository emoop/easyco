<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * payment_id is a PLAIN string, deliberately NOT a foreign key —
     * same posture as payments.order_id, but here it's not a forward
     * reference: Payment exists in this very package. It's still not
     * an FK because this domain does not validate that the referenced
     * Payment is actually CAPTURED at construction time
     * (payment-domain-design.md §3's own note — that's the caller's/an
     * application service's job, not a pure DB constraint per §5.2).
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id');

            $table->bigInteger('amount_minor');
            $table->char('amount_currency', 3);

            $table->string('reason')->nullable();
            $table->string('refunded_by')->nullable();

            // pending | completed | failed — see
            // EasyCo\Payment\Enums\PaymentRefundStatus.
            $table->string('status');

            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // Hot path for findByPaymentId().
            $table->index('payment_id', 'pay_refunds_payment_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
