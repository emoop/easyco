<?php

namespace EasyCo\Address\Persistence\Eloquent;

use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;

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
        $model->recipient_name = $address->recipientName();
        $model->phone = $address->phone();
        $model->country = $address->country();
        $model->city = $address->city();
        $model->postal_code = $address->postalCode();
        $model->address_line_1 = $address->addressLine1();
        $model->address_line_2 = $address->addressLine2();
        $model->carrier_code = $address->carrierCode();
        $model->pickup_point_reference = $address->pickupPointReference();
        $model->settlement = $address->settlement();

        $model->save();

        if ($address->id() === null) {
            $address->assignId((string) $model->id);
        }
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
            recipientName: $model->recipient_name,
            phone: $model->phone,
            country: $model->country,
            city: $model->city,
            postalCode: $model->postal_code,
            addressLine1: $model->address_line_1,
            addressLine2: $model->address_line_2,
            carrierCode: $model->carrier_code,
            pickupPointReference: $model->pickup_point_reference,
            settlement: $model->settlement,
        );
    }
}
