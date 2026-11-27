<?php

use App\Couriers\CourierDirectory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COUR-2a.6: a saved pick-up address of an INTEGRATED courier stores the courier's CODE (`speedy`), not its display name («Спиди»), the way an
 * order placed through the list picker always did. This converts the rows saved under a display name: only when the stored text is, after
 * normalisation (case and spacing), the code or the display name (bg or en) of an installed integrated courier: CourierDirectory::canonicalCode(),
 * the one mapping. Anything else (a plain courier name, an unknown text, a courier that is not installed) is left untouched.
 *
 * Safe to run twice (a converted row is already its code) and on an empty table. Only `addresses` changes; an order keeps what it stored (history).
 * `down` does nothing: the display name that was there is not recoverable, and the code means the same courier.
 */
return new class extends Migration
{
    public function up(): void
    {
        $directory = app(CourierDirectory::class);

        DB::table('addresses')
            ->whereNotNull('carrier_code')
            ->select('id', 'carrier_code')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($directory): void {
                foreach ($rows as $row) {
                    $code = $directory->canonicalCode((string) $row->carrier_code);

                    if ($code !== null && $code !== $row->carrier_code) {
                        DB::table('addresses')->where('id', $row->id)->update(['carrier_code' => $code]);
                    }
                }
            });
    }

    public function down(): void
    {
        // intentionally empty (see the class comment)
    }
};
