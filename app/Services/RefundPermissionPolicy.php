<?php

namespace App\Services;

use App\Services\Exceptions\RefundPermissionDeniedException;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Staff\Enums\Permission;

/**
 * THE ONE DERIVATION of "which permission records and pays out a refund": the
 * PAYOUT CHANNEL decides — cash from the register needs REFUND_CASH, bank needs
 * REFUND_BANK (shipping-domain-design.md §7.2.8). The panel's visibility clause
 * (OrderResource::moneyPermissionClause) and the service rule
 * (OrderRefunder, via assertMayRecord) both read permissionFor(), so they can
 * never disagree.
 *
 * mayRecord() asks the staff member logged into the panel (PanelStaffActor) and
 * resolves their permissions through AuthenticatedStaffResolver — the same two
 * collaborators the UI check already uses. NO acting staff member (a console
 * command, a queued job, an unauthenticated caller) is a refusal, not a pass:
 * an unattended path must not be able to record a payout the panel would not
 * show to anyone.
 */
final class RefundPermissionPolicy
{
    public function __construct(
        private readonly PanelStaffActor $actor,
        private readonly AuthenticatedStaffResolver $staff,
    ) {
    }

    public static function permissionFor(RefundChannel $channel): Permission
    {
        return match ($channel) {
            RefundChannel::CASH => Permission::REFUND_CASH,
            RefundChannel::BANK => Permission::REFUND_BANK,
        };
    }

    public function mayRecord(RefundChannel $channel): bool
    {
        $actor = $this->actor->current();

        if ($actor === null) {
            return false;
        }

        $staff = $this->staff->resolveById((string) $actor->getAuthIdentifier());

        return $staff !== null && $staff->can(self::permissionFor($channel));
    }

    /** @throws RefundPermissionDeniedException */
    public function assertMayRecord(RefundChannel $channel): void
    {
        if (! $this->mayRecord($channel)) {
            throw new RefundPermissionDeniedException($channel, self::permissionFor($channel));
        }
    }
}
