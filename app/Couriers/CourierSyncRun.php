<?php

namespace App\Couriers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A recorded points sync (table courier_sync_runs). No personal data, no credentials; see the migration.
 *
 * @property int $id
 * @property string $carrier_code
 * @property string $trigger
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string $outcome
 */
class CourierSyncRun extends Model
{
    public const OK = 'ok';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const REFUSED_SHRINK = 'refused_shrink';

    protected $table = 'courier_sync_runs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** A run that brought the list up to date. */
    public function succeeded(): bool
    {
        return in_array($this->outcome, [self::OK, self::PARTIAL], true);
    }
}
