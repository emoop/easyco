<?php

namespace EasyCo\Address\Persistence\Eloquent;

use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Maps the Address entity onto `addresses`. No unique-constraint
 * collision handling here, deliberately — nothing about this entity is
 * unique (a customer can plausibly save two addresses that happen to
 * look identical), so there is no exception-catching machinery to add,
 * unlike EloquentPromotionRepository's code-uniqueness handling.
 */
final class EloquentAddressRepository implements AddressRepository
{
    public function save(Address $address): void
    {
        $model = $address->id() !== null
            ? AddressModel::findOrFail($address->id())
            : new AddressModel();

        $model->account_id = $address->accountId();
        $model->delivery_type = $address->deliveryType()->value;
        $model->recipient_first_name = $address->recipientFirstName();
        $model->recipient_last_name = $address->recipientLastName();
        $model->phone = $address->phone();
        $model->country = $address->country();
        $model->city = $address->city();
        $model->postal_code = $address->postalCode();
        $model->address_line_1 = $address->addressLine1();
        $model->address_line_2 = $address->addressLine2();
        $model->carrier_code = $address->carrierCode();
        $model->pickup_point_reference = $address->pickupPointReference();
        $model->settlement = $address->settlement();
        $model->pickup_point_name = $address->pickupPointName();
        $model->pickup_point_address = $address->pickupPointAddress();
        $model->pickup_point_kind = $address->pickupPointKind();
        $model->region = $address->region();

        $model->save();

        if ($address->id() === null) {
            $address->assignId((string) $model->id);

            // ACC-2b: the FIRST address of an account becomes its default
            if ($model->account_id !== null) {
                $this->makeDefaultIfNone((int) $model->id, (int) $model->account_id);
            }
        }
    }

    /**
     * ONE statement: set the marker only while the account has no default. A concurrent insert that wins the race trips
     * UNIQUE(account_id, default_marker); that collision (SQLSTATE 23000 + driver code, CLAUDE.md rule 3) means "someone else
     * is the default now" and is swallowed. query builder, not Eloquent: updated_at must not move.
     */
    private function makeDefaultIfNone(int $id, int $accountId): void
    {
        try {
            DB::update(
                'UPDATE addresses SET default_marker = 1 WHERE id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM addresses WHERE account_id = ? AND default_marker = 1) AS d)',
                [$id, $accountId]
            );
        } catch (QueryException $e) {
            if (! self::isUniqueCollision($e)) {
                throw $e;
            }
        }
    }

    private static function isUniqueCollision(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverCode, [1062, 19], true);
    }

    public function defaultAddressId(string $accountId): ?string
    {
        $id = DB::table('addresses')->where('account_id', $accountId)->where('default_marker', 1)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Locks EVERY address row of the account (ordered by id, at most a handful: the cap is 20) before a default-changing operation
     * works, so two of them on one account queue up instead of racing into UNIQUE(account_id, default_marker). A fixed order means no deadlock.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function lockAccountRows(string $accountId): \Illuminate\Support\Collection
    {
        return DB::table('addresses')->where('account_id', $accountId)->orderBy('id')->lockForUpdate()->get(['id', 'default_marker']);
    }

    public function deleteOwned(string $id, string $accountId): bool
    {
        return DB::transaction(function () use ($id, $accountId): bool {
            $row = $this->lockAccountRows($accountId)->firstWhere('id', (int) $id);

            if ($row === null) {
                return false;
            }

            DB::table('addresses')->where('id', $row->id)->delete();

            if ($row->default_marker !== null) {
                $next = DB::table('addresses')->where('account_id', $accountId)->orderByDesc('updated_at')->orderByDesc('id')->value('id');

                if ($next !== null) {
                    DB::table('addresses')->where('id', $next)->update(['default_marker' => 1]);
                }
            }

            return true;
        });
    }

    public function setDefault(string $id, string $accountId): bool
    {
        return DB::transaction(function () use ($id, $accountId): bool {
            $row = $this->lockAccountRows($accountId)->firstWhere('id', (int) $id);

            if ($row === null) {
                return false;
            }

            if ($row->default_marker !== null) {
                return true;
            }

            DB::table('addresses')->where('account_id', $accountId)->whereNotNull('default_marker')->update(['default_marker' => null]);
            DB::table('addresses')->where('id', $row->id)->update(['default_marker' => 1]);

            return true;
        });
    }

    public function touchOwned(string $id, string $accountId): bool
    {
        return DB::table('addresses')->where('id', $id)->where('account_id', $accountId)->update(['updated_at' => now()]) > 0;
    }

    public function findById(string $id): ?Address
    {
        $model = AddressModel::find($id);

        return $model !== null ? $this->toDomainAddress($model) : null;
    }

    /** @return Address[] */
    public function findByAccountId(string $accountId): array
    {
        return AddressModel::where('account_id', $accountId)
            ->orderByRaw('default_marker IS NULL')   // ACC-2b: the default first, then the old order (id)
            ->orderBy('id')
            ->get()
            ->map(fn (AddressModel $model) => $this->toDomainAddress($model))
            ->all();
    }

    private function toDomainAddress(AddressModel $model): Address
    {
        return Address::reconstituteFromStorage(
            id: (string) $model->id,
            accountId: $model->account_id !== null ? (string) $model->account_id : null,
            deliveryType: AddressDeliveryType::from($model->delivery_type),
            recipientFirstName: $model->recipient_first_name,
            recipientLastName: $model->recipient_last_name,
            phone: $model->phone,
            country: $model->country,
            city: $model->city,
            postalCode: $model->postal_code,
            addressLine1: $model->address_line_1,
            addressLine2: $model->address_line_2,
            carrierCode: $model->carrier_code,
            pickupPointReference: $model->pickup_point_reference,
            settlement: $model->settlement,
            pickupPointName: $model->pickup_point_name,
            pickupPointAddress: $model->pickup_point_address,
            pickupPointKind: $model->pickup_point_kind,
            region: $model->region,
        );
    }
}
