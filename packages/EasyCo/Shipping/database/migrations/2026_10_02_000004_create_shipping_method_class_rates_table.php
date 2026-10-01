<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * class_code references shipping_classes.code (a UNIQUE string column), so
     * it must match that column EXACTLY — string(64), utf8mb4,
     * utf8mb4_unicode_ci — or MySQL rejects the foreign key or mishandles it.
     * ON DELETE / ON UPDATE RESTRICT: a class that a rate still uses can
     * neither be removed nor have its code rewritten. Deleting a METHOD
     * removes its rates (cascade). All names are explicit and short (rule 5).
     */
    public function up(): void
    {
        Schema::create('shipping_method_class_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('method_id');
            $table->string('class_code', 64)->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->bigInteger('amount_minor');

            $table->primary(['method_id', 'class_code'], 'ship_class_rates_primary');

            $table->foreign('method_id', 'ship_class_rates_method_id_foreign')
                ->references('id')->on('shipping_methods')
                ->cascadeOnDelete();

            $table->foreign('class_code', 'ship_class_rates_class_code_foreign')
                ->references('code')->on('shipping_classes')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_method_class_rates');
    }
};
