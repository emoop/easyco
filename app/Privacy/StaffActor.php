<?php

namespace App\Privacy;

/** The staff member behind an admin privacy request: the id and a name snapshot (the log keeps both, like the activity log). */
final class StaffActor
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
