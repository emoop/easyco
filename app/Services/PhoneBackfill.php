<?php

namespace App\Services;

use App\Settings\StoreCountry;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\DB;

/**
 * Stage PH-1: brings the phones already stored to E.164 where that is possible. Used by the migration `normalise_phones_to_e164`.
 *
 * For every order, saved address and order placement snapshot: a phone that is already E.164 is left as it is (counted `already`); a phone that
 * PARSES with the row's own country is rewritten as E.164 (`converted`); anything else (no country on the row, a number that is not valid) is left
 * UNTOUCHED (`left`). An order's phone key follows its phone. The phone keys of phone BLOCKS (order_blocks) are re-keyed the same way from the
 * number staff blocked, read with the store's country. IDEMPOTENT: a second run finds only `already` rows and changes nothing. Counts only, never a
 * number (CLAUDE.md: no personal data in a log or an output).
 */
final class PhoneBackfill
{
    public const TABLES = ['orders', 'addresses', 'order_placement_snapshots'];

    /**
     * @return array{tables: array<string, array{converted: int, left: int, already: int}>, blocks: array{converted: int, left: int, already: int}}
     */
    public static function run(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = self::table($table);
        }

        return ['tables' => $tables, 'blocks' => self::blocks()];
    }

    /** @return array{converted: int, left: int, already: int} */
    private static function table(string $table): array
    {
        $counts = ['converted' => 0, 'left' => 0, 'already' => 0];

        DB::table($table)->orderBy('id')->select(['id', 'phone', 'country'])->chunkById(500, function ($rows) use ($table, &$counts): void {
            foreach ($rows as $row) {
                $phone = (string) $row->phone;

                if (preg_match(IdentityKeys::E164_PATTERN, $phone) === 1) {
                    $counts['already']++;

                    continue;
                }

                $e164 = PhoneNumbers::e164($phone, $row->country === null ? null : (string) $row->country);

                if ($e164 === null) {
                    $counts['left']++;

                    continue;
                }

                $update = ['phone' => $e164];

                if ($table === 'orders') {
                    $update['phone_key'] = IdentityKeys::phone($e164);
                }

                DB::table($table)->where('id', $row->id)->update($update);
                $counts['converted']++;
            }
        });

        return $counts;
    }

    /** @return array{converted: int, left: int, already: int} */
    private static function blocks(): array
    {
        $counts = ['converted' => 0, 'left' => 0, 'already' => 0];
        $country = app(StoreCountry::class)->currentOrNull();

        DB::table('order_blocks')->where('type', 'phone')->orderBy('id')->select(['id', 'value_key', 'value_display'])->chunkById(500, function ($rows) use ($country, &$counts): void {
            foreach ($rows as $row) {
                if (preg_match(IdentityKeys::E164_PATTERN, (string) $row->value_key) === 1) {
                    $counts['already']++;

                    continue;
                }

                $key = PhoneNumbers::e164((string) $row->value_display, $country);

                if ($key === null) {
                    $counts['left']++;

                    continue;
                }

                DB::table('order_blocks')->where('id', $row->id)->update(['value_key' => $key]);
                $counts['converted']++;
            }
        });

        return $counts;
    }

    /** One line of counts for a migration's output. */
    public static function summary(array $result): string
    {
        $parts = [];

        foreach ($result['tables'] as $table => $counts) {
            $parts[] = "{$table}: converted {$counts['converted']}, left {$counts['left']}";
        }

        $parts[] = "order_blocks (phone): converted {$result['blocks']['converted']}, left {$result['blocks']['left']}";

        return 'Phones to E.164 - '.implode('; ', $parts).'.';
    }
}
