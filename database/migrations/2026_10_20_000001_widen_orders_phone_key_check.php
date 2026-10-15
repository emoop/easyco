<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PH-1 (phone validation): the phone key is the E.164 string (`+359888123456`, 8-16 characters) when the number parses, else the old last 9 digits.
     * The CHECK of stage C2-B (`ord_phone_key_check`: exactly nine digits) is replaced by one that allows both (CLAUDE.md rule 2: the invariant is
     * also a real constraint). The name is unchanged (19 characters, rule 5); MySQL / MariaDB only, like the original. IDEMPOTENT: when the
     * constraint already allows a plus, nothing is done.
     */
    public const NAME = 'ord_phone_key_check';

    public const OLD = "phone_key IS NULL OR phone_key REGEXP '^[0-9]{9}$'";

    public const NEW = "phone_key IS NULL OR phone_key REGEXP '^([0-9]{9}|[+][1-9][0-9]{6,14})$'";

    public function up(): void
    {
        $this->replaceWith(self::NEW, fn (string $clause): bool => str_contains($clause, '[+]'));
    }

    public function down(): void
    {
        // Rows holding an E.164 key would violate the old rule: they go back to the last nine digits first.
        if ($this->supportsCheck()) {
            DB::table('orders')->where('phone_key', 'like', '+%')->update(['phone_key' => DB::raw('RIGHT(phone_key, 9)')]);
        }

        $this->replaceWith(self::OLD, fn (string $clause): bool => ! str_contains($clause, '[+]'));
    }

    /** @param  callable(string): bool  $alreadyRight */
    private function replaceWith(string $expression, callable $alreadyRight): void
    {
        if (! $this->supportsCheck()) {
            return;
        }

        $clause = DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('constraint_name', self::NAME)
            ->value('check_clause');

        if (is_string($clause) && $alreadyRight($clause)) {
            return;
        }

        if ($clause !== null) {
            DB::statement('ALTER TABLE orders DROP CHECK '.self::NAME);
        }

        DB::statement('ALTER TABLE orders ADD CONSTRAINT '.self::NAME.' CHECK ('.$expression.')');
    }

    private function supportsCheck(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
