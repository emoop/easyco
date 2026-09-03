<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit short index/FK name (prefix "addr_"), same convention as
     * promotions'/pricing's own migrations — see those migrations'
     * docblocks for the MySQL 64-character identifier limit lesson
     * (CLAUDE.md rule 5).
     *
     * delivery_type stored as a plain string, never a native DB enum —
     * same convention as promotions.discount_type/status. Every
     * optional field is a plain nullable column; the real exclusivity
     * invariant between STREET_ADDRESS and PICKUP_POINT fields lives in
     * the Address domain class's constructor, not here (same posture
     * as Cart's own accountId/sessionToken XOR — there is no portable
     * DB-level conditional-nullability constraint).
     *
     * account_id cascadeOnDelete()'s — if an Account is deleted, its
     * saved addresses go with it, same reasoning as carts.account_id.
     * A null-accountId guest address is entirely unaffected since it
     * was never tied to one.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->nullable()
                ->constrained('accounts', indexName: 'addr_account_id_foreign')
                ->cascadeOnDelete();

            // street_address | pickup_point — see
            // EasyCo\Address\Enums\AddressDeliveryType.
            $table->string('delivery_type');

            $table->string('recipient_name');
            $table->string('phone');

            // STREET_ADDRESS fields — see Address::assertFieldsMatchDeliveryType().
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();

            // PICKUP_POINT fields, deliberately free-form — see
            // address-domain-design.md §3. carrier_code is NOT an enum
            // and pickup_point_reference is NOT validated against any
            // carrier's own system.
            $table->string('carrier_code')->nullable();
            $table->string('pickup_point_reference')->nullable();
            $table->string('settlement')->nullable();

            $table->timestamps();

            // Hot path for findByAccountId().
            $table->index('account_id', 'addr_account_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
