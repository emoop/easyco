<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_sales_clients', function (Blueprint $table) {
            $table->id();

            // Deliberately NOT unique — two clients can share a name (see
            // Client::changeName()/operational-sales-domain-design.md
            // §3.7: no script/case/format rule, and no uniqueness rule
            // either). That's precisely why the id must stay visible in
            // the UI, per the domain owner's own note.
            $table->string('name');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sales_clients');
    }
};
