<?php

namespace EasyCo\Staff\Persistence\Eloquent;

use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Exceptions\StaffEmailAlreadyRegisteredException;
use EasyCo\Staff\Staff;
use Illuminate\Database\QueryException;
use RuntimeException;

/** Maps the Staff entity onto `staff`. */
final class EloquentStaffRepository implements StaffRepository
{
    public function __construct(
        private readonly RoleRepository $roleRepository,
    ) {
    }

    public function save(Staff $staff): void
    {
        $model = $staff->id() !== null
            ? StaffModel::findOrFail($staff->id())
            : new StaffModel();

        $model->email = $staff->email();
        $model->password = $staff->passwordHash();
        $model->name = $staff->name();
        $model->role_id = $staff->role()->id();
        $model->is_active = $staff->isActive();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isEmailUniqueViolation($e)) {
                throw StaffEmailAlreadyRegisteredException::forEmail($staff->email());
            }

            throw $e;
        }

        if ($staff->id() === null) {
            $staff->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Staff
    {
        $model = StaffModel::find($id);

        return $model !== null ? $this->toDomainStaff($model) : null;
    }

    public function findByEmail(string $email): ?Staff
    {
        $model = StaffModel::where('email', strtolower($email))->first();

        return $model !== null ? $this->toDomainStaff($model) : null;
    }

    public function any(): bool
    {
        return StaffModel::withTrashed()->exists();
    }

    /**
     * Detects a violation of staff_email_unique — SQLSTATE 23000 +
     * driver error code (MySQL 1062 / SQLite 19) is the primary check,
     * then errorInfo[2] narrows to this specific constraint — never
     * $e->getMessage() string matching (CLAUDE.md rule 3, mirrors
     * EloquentAccountRepository::isEmailUniqueViolation()).
     */
    private function isEmailUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'staff_email_unique')
            || str_contains($driverErrorMessage, 'staff.email');
    }

    private function toDomainStaff(StaffModel $model): Staff
    {
        $role = $this->roleRepository->findById((string) $model->role_id);

        if ($role === null) {
            // A Staff row pointing at a nonexistent Role is a
            // corrupted-data state, not a normal null case — fail loud
            // here rather than fabricate a Role, per this project's own
            // "fail-loud over silent fallback" principle.
            throw new RuntimeException(
                "Staff \"{$model->id}\" references role_id \"{$model->role_id}\", which does not exist."
            );
        }

        return Staff::reconstituteFromStorage(
            id: (string) $model->id,
            email: $model->email,
            passwordHash: $model->password,
            name: $model->name,
            role: $role,
            isActive: $model->is_active,
        );
    }
}
