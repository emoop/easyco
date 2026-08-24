<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_size_guides', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // universal | brand | category | product — see catalog-domain-design.md
            // "Size guide" §. Deliberately a plain string, not an enum column
            // type, for SQLite/MySQL portability; validated in the domain layer.
            $table->string('scope');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_size_guides');
    }
};
