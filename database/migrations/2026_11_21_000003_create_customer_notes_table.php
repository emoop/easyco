<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-4a (acc-4-gdpr-design.md §3): the staff note about a customer, one per email (`email_key`: the lower-cased email, as in orders.email_key).
     * Plain text, at most 2000 characters, never blank: CHECK `cnote_body_check` (explicit short name, rule 5). Deleted when the person is forgotten (D3).
     * `staff_id` is a plain number (no foreign key: a staff member leaving must not touch a note). Idempotent (rule 6).
     */
    public function up(): void
    {
        if (! Schema::hasTable('customer_notes')) {
            Schema::create('customer_notes', function (Blueprint $table) {
                $table->id();
                $table->string('email_key', 255);
                $table->text('body');
                $table->unsignedBigInteger('staff_id')->nullable();
                $table->timestamps();

                $table->unique('email_key', 'cnote_email_key_unique');
            });
        }

        if ($this->supportsCheck() && ! $this->hasCheck()) {
            DB::statement("ALTER TABLE customer_notes ADD CONSTRAINT cnote_body_check CHECK (TRIM(body) <> '' AND CHAR_LENGTH(body) <= 2000)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }

    private function hasCheck(): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'customer_notes')
            ->where('constraint_name', 'cnote_body_check')
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
