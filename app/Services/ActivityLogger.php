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
 * ONE DELIBERATE EXCEPTION: logDeleted() writes REGARDLESS of that
 * setting (catalog-domain-design.md §3.19.10's first recorded decision).
 * Routine field-change telemetry staying opt-in is fine; the record that a
 * product or variation was DESTROYED is not telemetry — it is the only
 * remaining trace of the row, the operation cannot be undone, and the
 * identifiers it frees may already be printed on a physical label. Under
 * the plain gate this method would silently record nothing on a default
 * installation, which would make §3.19's own G-D7 a promise the code does
 * not keep. It is the only method that bypasses the gate, and the only
 * action value that does. Its rows are also never age-pruned — see
 * PruneActivityLog and ACTION_DELETED below.
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
    /**
     * The one action value meaning "this record was destroyed" — never
     * age-pruned (PruneActivityLog), and the only value logDeleted()
     * writes. A constant rather than a literal repeated in three places,
     * so a future rename cannot desynchronise the writer from the prune
     * exclusion.
     */
    public const ACTION_DELETED = 'deleted';

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

    /**
     * Records that $entityId was permanently deleted, with a full snapshot
     * of what went with it (catalog-domain-design.md §3.19.10, G-D7).
     *
     * THE SNAPSHOT GOES IN `old_value`, AND `new_value` STAYS NULL: the
     * column pair already means "what it was" / "what it became", and a
     * deletion has no new state — everything in the payload describes the
     * state that no longer exists. Stored as JSON so one row carries the
     * whole operation's inventory (name, base SKU, slug, every variation's
     * id/SKU/barcode/combination/status, price-list items, costs, stock and
     * the configuration row counts) rather than one row per fact, which
     * would make "this deletion is one event" impossible to read back.
     *
     * Who and when are NOT part of $snapshot — they are this table's own
     * staff_id/staff_name/occurred_at columns, and duplicating them inside
     * the payload would give the same fact two homes.
     *
     * @param array<string, mixed> $snapshot
     */
    public function logDeleted(string $entityType, string $entityId, array $snapshot): void
    {
        $this->write(
            entityType: $entityType,
            entityId: $entityId,
            action: self::ACTION_DELETED,
            field: null,
            oldValue: json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            newValue: null,
            bypassEnabledGate: true,
        );
    }

    private function write(
        string $entityType,
        string $entityId,
        string $action,
        ?string $field,
        ?string $oldValue,
        ?string $newValue,
        bool $bypassEnabledGate = false,
    ): void {
        if (! $bypassEnabledGate && $this->siteSettings->get('admin.activity_log_enabled') !== '1') {
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
