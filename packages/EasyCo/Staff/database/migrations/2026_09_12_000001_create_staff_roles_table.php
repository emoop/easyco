<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table name is `staff_roles`, NOT `roles` — `roles` would be the
     * only table in this project with a name that generic, and it's
     * exactly the table name spatie/laravel-permission (the de facto
     * standard permissions package in the Laravel ecosystem) uses by
     * convention. An unrelated future package install would collide
     * head-on. `staff_roles` costs nothing now and avoids a
     * rename-with-data-in-it later. The domain class stays `Role` —
     * only this table is prefixed; Role itself has no knowledge of
     * table names at all.
     *
     * NO unique constraint on `name` — the design doc does not specify
     * role-name uniqueness as an invariant, and one isn't invented here.
     * NO softDeletes() — no delete/deactivate lifecycle exists for Role
     * yet (future admin-UI work, staff-access-domain-design.md §10).
     */
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_roles');
    }
};
