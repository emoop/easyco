<?php

namespace EasyCo\Staff\Contracts;

/**
 * The infrastructure boundary for password hashing — deliberately an
 * INDEPENDENT copy of EasyCo\Account\Contracts\PasswordHasher, not a
 * reuse of it. staff-access-domain-design.md §2 states "Staff never
 * references Account and Account never references Staff" — that
 * principle holds at the infrastructure/contract level too, not just
 * the domain-entity level. This project's own stated precedent
 * (PromotionScope mirroring PriceListScope rather than sharing code,
 * per principles-and-workflow.md) is exactly this situation: a
 * near-identical need, an independent implementation, no real
 * duplication cost.
 *
 * The Staff domain class itself never imports
 * Illuminate\Support\Facades\Hash; only an implementation of this
 * contract does.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;
}
