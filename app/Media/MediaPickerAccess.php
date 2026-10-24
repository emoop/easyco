<?php

namespace App\Media;

use App\Services\AuthenticatedStaffResolver;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Facades\Filament;

/**
 * Who may use the media picker (ML-2): whoever may edit a place that shows a picture — the content permission (HTML blocks, pages, the Design screen), the catalog permission (the «Медия» page,
 * products) or the taxonomy permission (brands: the logo). The picker's Livewire component checks it again on EVERY action, never only when it is drawn.
 */
final class MediaPickerAccess
{
    public const PERMISSIONS = [Permission::CONTENT_MANAGE, Permission::PRODUCT_MANAGE, Permission::TAXONOMY_MANAGE];

    public static function allowed(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof StaffModel) {
            return false;
        }

        $staff = app(AuthenticatedStaffResolver::class)->resolveById((string) $user->getAuthIdentifier());

        if ($staff === null) {
            return false;
        }

        foreach (self::PERMISSIONS as $permission) {
            if ($staff->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
