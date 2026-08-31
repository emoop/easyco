<?php

namespace EasyCo\Account\Persistence\Eloquent;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Eloquent model backing the `customer` guard's `accounts`
 * provider (config/auth.php) — see account-domain-design.md §2.
 * Implements Authenticatable itself (via the trait) rather than
 * extending Laravel's own App\Models\User, keeping storefront
 * customers structurally separate from any future staff/admin login.
 */
class AccountModel extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = ['email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
