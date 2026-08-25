<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_sales_transactions', function (Blueprint $table) {
            $table->id();

            // pos | web — see EasyCo\OperationalSales\Enums\Channel.
            $table->string('channel');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sales_transactions');
    }
};
