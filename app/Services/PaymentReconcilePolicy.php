<?php

namespace App\Services;

use App\Services\Exceptions\PaymentReconcilePermissionDeniedException;
use EasyCo\Staff\Enums\Permission;

/**
 * THE ONE DERIVATION of "may this staff member accept a short or over bank transfer": the
 * `payment_reconcile` permission (Administrator only; refunds R4a-3, shipping-domain-design.md §7.2.20 §5).
 * The panel's visibility clause (R4a-4) and the service rule (PaymentReceiptRecorder::acceptMismatch())
 * both read this, so they can never disagree. It is RefundPermissionPolicy's twin and resolves in the same
 * way: the staff member logged into the panel (PanelStaffActor), their permissions through
 * AuthenticatedStaffResolver. NO acting staff member (a console command, a queued job) is a refusal, not a
 * pass: an unattended path must not be able to make a money decision the panel would not show to anyone.
 */
final class PaymentReconcilePolicy
{
    public const PERMISSION = Permission::PAYMENT_RECONCILE;

    public function __construct(
        private readonly PanelStaffActor $actor,
        private readonly AuthenticatedStaffResolver $staff,
    ) {
    }

    public function mayReconcile(): bool
    {
        $actor = $this->actor->current();

        if ($actor === null) {
            return false;
        }

        $staff = $this->staff->resolveById((string) $actor->getAuthIdentifier());

        return $staff !== null && $staff->can(self::PERMISSION);
    }

    /** @throws PaymentReconcilePermissionDeniedException */
    public function assertMayReconcile(): void
    {
        if (! $this->mayReconcile()) {
            throw new PaymentReconcilePermissionDeniedException();
        }
    }
}
