<?php

namespace EasyCo\Shipping\Persistence\Eloquent;

use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Exceptions\ShippingClassCodeAlreadyExistsException;
use EasyCo\Shipping\ShippingClass;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps the ShippingClass entity onto `shipping_classes`. A duplicate code is
 * detected by SQLSTATE 23000 plus the driver error code (MySQL 1062 / SQLite
 * 19) and then the constraint name — never by matching the message text alone
 * (CLAUDE.md rule 3; same shape as EloquentPromotionRepository).
 */
final class EloquentShippingClassRepository implements ShippingClassRepository
{
    public function save(ShippingClass $class): void
    {
        $model = $class->id() !== null
            ? ShippingClassModel::findOrFail($class->id())
            : new ShippingClassModel();

        // code is immutable on the entity; writing it again is a no-op for an
        // existing row and the first write for a new one.
        $model->code = $class->code();
        $model->name = $class->name();
        $model->description = $class->description();

        try {
            $model->save();
        } catch (QueryException $e) {
            if ($this->isCodeUniqueViolation($e)) {
                throw ShippingClassCodeAlreadyExistsException::forCode($class->code());
            }

            throw $e;
        }

        if ($class->id() === null) {
            $class->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?ShippingClass
    {
        $model = ShippingClassModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByCode(string $code): ?ShippingClass
    {
        $model = ShippingClassModel::where('code', $code)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return ShippingClass[] */
    public function all(): array
    {
        return ShippingClassModel::query()
            ->orderBy('code')
            ->get()
            ->map(fn (ShippingClassModel $model) => $this->toDomain($model))
            ->all();
    }

    public function findDefault(): ?ShippingClass
    {
        $model = ShippingClassModel::query()->where('is_default', true)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function markDefault(?string $id): void
    {
        DB::transaction(function () use ($id): void {
            // lock the current default first; clear it BEFORE setting the new one, so the unique marker is never doubled
            ShippingClassModel::query()->where('is_default', true)->lockForUpdate()->get();
            ShippingClassModel::query()->where('is_default', true)->update(['is_default' => false, 'default_marker' => null]);

            if ($id !== null) {
                ShippingClassModel::query()->whereKey($id)->update(['is_default' => true, 'default_marker' => 1]);
            }
        });
    }

    public function delete(string $id): void
    {
        ShippingClassModel::query()->whereKey($id)->delete();
    }

    private function isCodeUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState !== '23000' || ! in_array($driverErrorCode, [1062, 19], true)) {
            return false;
        }

        $driverErrorMessage = (string) ($errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'ship_classes_code_unique')
            || str_contains($driverErrorMessage, 'shipping_classes.code');
    }

    private function toDomain(ShippingClassModel $model): ShippingClass
    {
        return ShippingClass::reconstituteFromStorage(
            id: (string) $model->id,
            name: $model->name,
            code: $model->code,
            description: $model->description,
        );
    }
}
