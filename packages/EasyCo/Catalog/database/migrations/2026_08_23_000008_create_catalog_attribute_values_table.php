<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('attribute_definition_id')
                ->constrained('catalog_attribute_definitions')
                ->cascadeOnDelete();

            $table->string('value');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['attribute_definition_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_attribute_values');
    }
};
