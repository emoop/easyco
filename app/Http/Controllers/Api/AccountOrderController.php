<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountOrderReader;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ACC-2: the logged-in customer's OWN orders (inside `auth:customer` + EnsureAccountSessionIsCurrent, under the `account-read` limiter).
 *
 *  - GET /api/account/orders?page=N: newest first, 20 a page, only this account's orders.
 *  - GET /api/account/orders/{number}: one order of THIS account. Someone else's, a guest order and a number that does not exist answer the SAME 404 body.
 *
 * What an answer holds, and what never leaves, is AccountOrderReader's business (the same facts as the order-received page and the confirmation mail).
 */
class AccountOrderController extends Controller
{
    use AccountApi;

    public function __construct(
        private readonly AccountOrderReader $orders,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $request->validate(['page' => 'sometimes|integer|min:1|max:100000'])['page'] ?? 1;

        return response()->json($this->orders->list($this->account(), (int) $page));
    }

    public function show(string $number): JsonResponse
    {
        $order = $this->orders->detail($this->account(), $number);

        return $order === null
            ? response()->json(['message' => $this->says('orders.not_found')], 404)
            : response()->json(['data' => $order]);
    }

    private function account(): AccountModel
    {
        /** @var AccountModel $account */
        $account = Auth::guard('customer')->user();

        return $account;
    }
}
