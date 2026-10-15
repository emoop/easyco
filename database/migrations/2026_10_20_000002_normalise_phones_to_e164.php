<?php

use App\Services\PhoneBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * PH-1 (phone validation): the phones already stored (orders, saved addresses, placement snapshots) become E.164 wherever they parse with the
     * row's own country; the others are left UNTOUCHED, and both counts are printed (counts only, never a number). The order's phone key follows its
     * phone and the phone blocks are re-keyed (PhoneBackfill). IDEMPOTENT: a second run converts nothing. There is no `down`: the original text of a
     * phone is not kept (E.164 is the same number), and nothing depends on the old form.
     */
    public function up(): void
    {
        $result = PhoneBackfill::run();
        $line = PhoneBackfill::summary($result);

        Log::info('phones.normalised_to_e164', $result);
        echo '  '.$line.PHP_EOL;
    }

    public function down(): void
    {
        // Intentionally empty: see above.
    }
};
