<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * ViewRecord::authorizeAccess() already correctly calls
 * abort_unless(canView($record), 403) by default (confirmed against the
 * installed source — same established note as ProductResource\Pages\
 * ViewProduct/RoleResource\Pages\ViewRole) — no override needed here.
 *
 * FOUR HEADER ACTIONS, AS OF order-lifecycle-design.md §10 stage 7b —
 * the page's still-read-only D1 posture (no create/edit/delete, no form)
 * gains its first real writes: Confirm/Ship/Deliver/"Mark as received",
 * each an OrderResource::*Action() factory so the object is testable
 * without Livewire (the same reason ProductResource's own actions are
 * static methods there, not inlined here — see ViewProduct's identical
 * note). Every gate, every refusal and every success notification is
 * that factory's own job; this page stays the two-line adapter it always
 * was.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OrderResource::confirmAction(),
            OrderResource::shipAction(),
            OrderResource::deliverAction(),
            OrderResource::markAsReceivedAction(),
        ];
    }
}
