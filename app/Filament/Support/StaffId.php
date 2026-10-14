<?php

namespace App\Filament\Support;

use Illuminate\Support\Facades\Auth;

/** The id of the staff member using the admin panel right now (the `staff` guard), or null: for "who saved it" columns. */
final class StaffId
{
    public static function current(): ?string
    {
        $id = Auth::guard('staff')->id();

        return $id === null ? null : (string) $id;
    }
}
