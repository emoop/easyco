<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `order_blocks` (cod-protection-design.md section 3.1, stage C2-B): a block of cash on delivery (never the whole site) for an IP, a phone or
     * an email. An APP-LEVEL table (it sits beside `order_attributions`; `EasyCo\Order` knows nothing of it).
     *
     *  - `value_key` is the KEY of an identifier (EasyCo\Order\IdentityKeys: the last nine digits of a phone, a lower-cased email, an IPv4
     *    address or the /64 prefix of an IPv6 one); `value_display` is what staff saw when blocking (masked in every list but the full IP
     *    for ORDER_MANAGE). A block is PERSONAL DATA processed to prevent fraud and never used for marketing; its own clock is its duration
     *    (`order-blocks:prune` deletes a row 30 days after it ended).
     *  - ACTIVE = `revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now)`; `expires_at` NULL is permanent. At most one active block
     *    per (type, key): re-blocking extends or replaces it (the service's rule; an index on (type, value_key) serves the lookup).
     *  - `source_order_id`, `created_by` and `revoked_by` have NO foreign keys: a block outlives the order it was made from and the staff
     *    member who made it (survives erasure).
     *
     * CLAUDE.md rule 2 (MySQL/MariaDB only), explicit short names (rule 5): `oblk_type_check` (16 characters). Idempotent (rule 6): a table or
     * CHECK that is already there is left alone, in up() and in down().
     */
    public const CHECKS = [
        'oblk_type_check' => "type IN ('ip','phone','email')",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('order_blocks')) {
            Schema::create('order_blocks', function (Blueprint $table) {
                $table->id();
                $table->string('type', 8);
                $table->string('value_key', 255);
                $table->string('value_display', 255);
                $table->string('reason', 500)->nullable();
                $table->unsignedBigInteger('source_order_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();
                $table->timestamps();

                $table->index(['type', 'value_key'], 'oblk_type_key_index');
            });
        }

        if ($this->supportsCheck()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE order_blocks ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_blocks');
    }

    private function hasCheck(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'order_blocks')
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
