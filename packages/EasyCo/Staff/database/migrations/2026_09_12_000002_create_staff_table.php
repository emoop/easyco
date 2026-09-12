<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FK target is `staff_roles`, not `roles` — see
     * 2026_09_12_000001_create_staff_roles_table.php's own docblock for
     * why.
     *
     * restrictOnDelete() on role_id: a Role currently assigned to any
     * Staff must not be deletable out from under them. No Role-delete
     * operation exists yet in this task, but the constraint should
     * exist from day one, per CLAUDE.md's "every business invariant
     * enforced by app code must also be a real DB constraint wherever
     * physically possible" rule.
     */
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->string('name');

            $table->foreignId('role_id')
                ->constrained('staff_roles', indexName: 'staff_role_id_foreign')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('email', 'staff_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
