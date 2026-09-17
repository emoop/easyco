<?php

namespace App\Services;

use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Facades\Filament;

/**
 * A compact, generic activity log — app/ layer, not an EasyCo\* domain
 * package, mirroring App\Services\DetachProductFromCatalogLookup's own
 * "cross-package composition lives in app/" precedent (this is a
 * factual record, not a protected business invariant). Plain
 * infrastructure: no domain validation beyond what the migration's own
 * column types already enforce.
 *
 * GATED BY admin.activity_log_enabled — checked once, inside write()
 * itself, so no caller (CreateProduct/EditProduct today, any future
 * consumer) needs to separately remember to check the setting; a
 * single source of truth, off by default (LocaleSettings' own Tab 2).
 *
 * Both methods resolve the current staff (id + name snapshot) from the
 * panel guard internally — callers never pass staff info explicitly,
 * mirroring AuthorizesViaStaffPermission::staffCanForAction()'s own
 * `Filament::auth()->user()` resolution. A null/non-StaffModel
 * authenticated user (a console command, a queued job with no request
 * context) logs with a null staff_id/staff_name rather than throwing —
 * the History page's own "System" fallback exists specifically for
 * this case.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly SiteSettingsRepository $siteSettings,
    ) {
    }

    public function logCreated(string $entityType, string $entityId): void
    {
        $this->write($entityType, $entityId, 'created', null, null, null);
    }

    public function logFieldChanged(string $entityType, string $entityId, string $field, ?string $oldValue, ?string $newValue): void
    {
        $this->write($entityType, $entityId, 'updated', $field, $oldValue, $newValue);
    }

    private function write(string $entityType, string $entityId, string $action, ?string $field, ?string $oldValue, ?string $newValue): void
    {
        if ($this->siteSettings->get('admin.activity_log_enabled') !== '1') {
            return;
        }

        $staff = $this->currentStaff();

        ActivityLogModel::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'staff_id' => $staff?->id,
            'staff_name' => $staff?->name,
            'occurred_at' => new DateTimeImmutable(),
        ]);
    }

    private function currentStaff(): ?StaffModel
    {
        $authenticatedModel = Filament::auth()->user();

        return $authenticatedModel instanceof StaffModel ? $authenticatedModel : null;
    }
}
