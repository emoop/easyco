<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Support\Facades\DB;

/**
 * The write API of a privacy request's `actions`: what a listener of `customer.erased` (or the eraser itself) reports. Row-locked read-modify-write, so
 * two listeners adding a key at the same time keep both. The key and the value are validated (PrivacyActions); an invalid one throws, and the hook's
 * containment logs it without undoing the erasure.
 */
final class PrivacyLog
{
    public function addAction(int $requestId, string $key, bool|int|string $value = true): void
    {
        PrivacyActions::assertValidActions([$key => $value]);

        DB::transaction(function () use ($requestId, $key, $value): void {
            $request = PrivacyRequestModel::query()->lockForUpdate()->findOrFail($requestId);
            $actions = $request->actions ?? [];
            $actions[$key] = $value;
            $request->actions = $actions;
            $request->save();
        });
    }
}
