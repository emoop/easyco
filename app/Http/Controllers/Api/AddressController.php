<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The Address HTTP surface — see address-domain-design.md §1/§2.
 *
 * store() works for a guest or a logged-in customer, auto-associating
 * to the account when logged in — exactly the same
 * Auth::guard('customer')->check() pattern CartController::
 * currentOrNewCart() uses for its own accountId resolution.
 *
 * index()/update() are auth:customer-only (see routes/api.php): a
 * guest address has no provable ownership after the fact, so listing
 * and updating are simply not available to guests at all, by design,
 * not an oversight — there is no equivalent of Cart's session-token
 * identification here.
 *
 * update()'s ownership check returns 404, not 403, for an address
 * belonging to a different account — same "don't reveal that a
 * resource exists for someone else" reasoning
 * ProductCategoryController::destroy()/findOwnedPivot() already uses
 * for a cross-product pivot.
 */
class AddressController extends Controller
{
    public function __construct(
        private readonly AddressRepository $addresses,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->validationRules());

        $address = Address::create(
            deliveryType: AddressDeliveryType::from($validated['delivery_type']),
            recipientName: $validated['recipient_name'],
            phone: $validated['phone'],
            accountId: $this->currentAccountId(),
            country: $validated['country'] ?? null,
            city: $validated['city'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            addressLine1: $validated['address_line_1'] ?? null,
            addressLine2: $validated['address_line_2'] ?? null,
            carrierCode: $validated['carrier_code'] ?? null,
            pickupPointReference: $validated['pickup_point_reference'] ?? null,
            settlement: $validated['settlement'] ?? null,
        );

        $this->addresses->save($address);

        return response()->json($this->toArray($address), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $accountId = (string) Auth::guard('customer')->id();

        $items = array_map(
            fn (Address $address) => $this->toArray($address),
            $this->addresses->findByAccountId($accountId)
        );

        return response()->json(['data' => $items]);
    }

    public function update(Request $request, string $addressId): JsonResponse
    {
        $address = $this->addresses->findById($addressId);

        if ($address === null || $address->accountId() !== (string) Auth::guard('customer')->id()) {
            return response()->json([
                'message' => "No address \"{$addressId}\" found.",
            ], 404);
        }

        $validated = $request->validate($this->validationRules());

        $address->update(
            deliveryType: AddressDeliveryType::from($validated['delivery_type']),
            recipientName: $validated['recipient_name'],
            phone: $validated['phone'],
            country: $validated['country'] ?? null,
            city: $validated['city'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            addressLine1: $validated['address_line_1'] ?? null,
            addressLine2: $validated['address_line_2'] ?? null,
            carrierCode: $validated['carrier_code'] ?? null,
            pickupPointReference: $validated['pickup_point_reference'] ?? null,
            settlement: $validated['settlement'] ?? null,
        );

        $this->addresses->save($address);

        return response()->json($this->toArray($address));
    }

    /** @return array<string, string> */
    private function validationRules(): array
    {
        return [
            'delivery_type' => 'required|in:street_address,pickup_point',
            'recipient_name' => 'required|string',
            'phone' => 'required|string',
            'country' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'city' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'address_line_1' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'postal_code' => 'nullable|prohibited_if:delivery_type,pickup_point|string',
            'address_line_2' => 'nullable|prohibited_if:delivery_type,pickup_point|string',
            'carrier_code' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
            'pickup_point_reference' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
            'settlement' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
        ];
    }

    private function currentAccountId(): ?string
    {
        return Auth::guard('customer')->check() ? (string) Auth::guard('customer')->id() : null;
    }

    private function toArray(Address $address): array
    {
        return [
            'id' => $address->id(),
            'account_id' => $address->accountId(),
            'delivery_type' => $address->deliveryType()->value,
            'recipient_name' => $address->recipientName(),
            'phone' => $address->phone(),
            'country' => $address->country(),
            'city' => $address->city(),
            'postal_code' => $address->postalCode(),
            'address_line_1' => $address->addressLine1(),
            'address_line_2' => $address->addressLine2(),
            'carrier_code' => $address->carrierCode(),
            'pickup_point_reference' => $address->pickupPointReference(),
            'settlement' => $address->settlement(),
        ];
    }
}
