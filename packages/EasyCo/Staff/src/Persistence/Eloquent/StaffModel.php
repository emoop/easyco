<?php

namespace EasyCo\Staff\Persistence\Eloquent;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Eloquent model intended to back a future `staff` guard's
 * provider (config/auth.php) — see staff-access-domain-design.md §2.
 * Implements Authenticatable itself (via the trait), same posture
 * EasyCo\Account\Persistence\Eloquent\AccountModel already takes,
 * keeping staff structurally separate from both `web` and `customer`.
 *
 * NO GUARD IS WIRED TO THIS MODEL YET — that is Part 2 of
 * staff-access-domain-design.md (the `staff` guard + permission
 * middleware), deliberately out of scope for this task. This class
 * exists now because Staff persistence needs it regardless of when the
 * guard itself is registered.
 */
class StaffModel extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use SoftDeletes;

    protected $table = 'staff';

    protected $fillable = ['email', 'password', 'name', 'role_id', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['is_active' => 'boolean'];
}
