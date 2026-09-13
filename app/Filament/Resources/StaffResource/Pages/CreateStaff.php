<?php

namespace App\Filament\Resources\StaffResource\Pages;

use App\Filament\Resources\StaffResource;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Staff;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Write-interception per admin-panel-design.md §5. */
class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $role = app(RoleRepository::class)->findById((string) $data['role_id']);

        if ($role === null) {
            throw new RuntimeException("Role \"{$data['role_id']}\" could not be found.");
        }

        $passwordHash = app(PasswordHasher::class)->hash($data['password']);

        $staff = Staff::create($data['email'], $passwordHash, $data['name'], $role);

        app(StaffRepository::class)->save($staff);

        return StaffModel::find($staff->id());
    }
}
