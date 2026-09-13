<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5, plus this page's own
 * decision resolving staff-access-domain-design.md §12.2's explicitly
 * deferred question: nothing prevents deactivate() from being called on
 * the last remaining active staff member at the domain layer, so the
 * guard is enforced here instead, where StaffRepository::countActive()
 * gives the cross-Staff visibility a single Staff aggregate cannot have.
 */
class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $staff = app(StaffRepository::class)->findById((string) $record->id);

        if ($staff === null) {
            throw new RuntimeException("Staff \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        $wasActive = $staff->isActive();
        $willBeActive = (bool) ($data['is_active'] ?? $staff->isActive());

        if ($wasActive && ! $willBeActive) {
            $activeCount = app(StaffRepository::class)->countActive();

            if ($activeCount <= 1) {
                Notification::make()
                    ->title(__('staff.notifications.cannot_deactivate_last_active'))
                    ->danger()
                    ->send();

                $this->halt();
            }
        }

        if ($willBeActive && ! $wasActive) {
            $staff->reactivate();
        } elseif (! $willBeActive && $wasActive) {
            $staff->deactivate();
        }

        if (filled($data['password'] ?? null)) {
            $staff->changePasswordHash(app(PasswordHasher::class)->hash($data['password']));
        }

        $selectedRole = app(RoleRepository::class)->findById((string) $data['role_id']);

        if ($selectedRole === null) {
            throw new RuntimeException("Role \"{$data['role_id']}\" could not be found.");
        }

        if ($selectedRole->id() !== $staff->role()->id()) {
            $staff->changeRole($selectedRole);
        }

        app(StaffRepository::class)->save($staff);

        return StaffModel::find($staff->id());
    }
}
