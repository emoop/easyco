<?php

namespace App\Security\Lockout;

/** ACC-1: the storefront customers' login lockout (scope `customer`): the same steps and the same SECURITY_MODE rule as the admin's, no alarm mail. */
final class CustomerLoginLockout extends LoginLockout
{
    public function __construct()
    {
        parent::__construct('customer');
    }
}
