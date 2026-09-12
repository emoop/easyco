<?php

namespace EasyCo\Staff\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `staff_roles` — see
 * 2026_09_12_000001_create_staff_roles_table.php for the authoritative
 * column list (and for why the table is named `staff_roles`, not
 * `roles`). This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Staff\Role.
 */
class RoleModel extends Model
{
    protected $table = 'staff_roles';

    protected $fillable = ['name', 'permissions', 'is_system'];

    protected $casts = [
        'permissions' => 'array',
        'is_system' => 'boolean',
    ];
}
