<?php

namespace App\Privacy\Persistence;

use App\Privacy\PrivacyActions;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One privacy request (acc-4-gdpr-design.md §3); an erase is a row of the forget log. `actions` and `manual_steps` are JSON and checked here on every
 * save: an action key is one of PrivacyActions::CORE or a listener key of the closed SHAPE (PrivacyActions::isValidKey), its value a bool, an integer or
 * a short plain string; a manual step is exactly {key, done_at, staff_id}.
 */
class PrivacyRequestModel extends Model
{
    public const KIND_EXPORT = 'export';

    public const KIND_ERASE = 'erase';

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    protected $table = 'privacy_requests';

    protected $guarded = [];

    protected $casts = [
        'actions' => 'array',
        'manual_steps' => 'array',
        'clear_until' => 'datetime',
        'export_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            PrivacyActions::assertValidActions($request->actions ?? []);
            self::assertValidManualSteps($request->manual_steps ?? []);
        });
    }

    /** @param array<mixed> $steps */
    private static function assertValidManualSteps(array $steps): void
    {
        foreach ($steps as $step) {
            if (! is_array($step) || array_keys($step) !== ['key', 'done_at', 'staff_id'] || ! is_string($step['key']) || ! PrivacyActions::isValidKey($step['key'])
                || ($step['done_at'] !== null && ! is_string($step['done_at'])) || ($step['staff_id'] !== null && ! is_int($step['staff_id']))) {
                throw new InvalidArgumentException('A manual step is exactly {key, done_at, staff_id}.');
            }
        }
    }
}
