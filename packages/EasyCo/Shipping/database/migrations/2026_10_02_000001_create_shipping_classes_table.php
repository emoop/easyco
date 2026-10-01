<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `code` is the stable machine-facing reference a Variation will carry, and
     * shipping_method_class_rates.class_code is a FOREIGN KEY onto it. A MySQL
     * foreign key needs both columns to have the same type, length, charset and
     * collation, so all four are written out here AND on the referencing column
     * rather than left to server defaults. The format (lowercase a-z, 0-9,
     * single "_" or "-" separators, 1-64) is enforced by the domain; a
     * case-insensitive collation is therefore harmless, and UNIQUE is the real
     * backstop against a duplicate (CLAUDE.md rule 2). Index names are explicit
     * and short (rule 5).
     */
    public function up(): void
    {
        Schema::create('shipping_classes', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('name');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique('code', 'ship_classes_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_classes');
    }
};
