<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');

            // text | number | boolean | select | multiselect — see
            // EasyCo\Catalog\Enums\AttributeType. Only SELECT is usable as
            // a variation axis (AttributeDefinition::assertUsableAsVariationAxis()).
            $table->string('type');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_attribute_definitions');
    }
};
