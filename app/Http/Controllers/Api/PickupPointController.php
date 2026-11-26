<?php

namespace App\Http\Controllers\Api;

use App\Couriers\OfferablePickupPoints;
use App\Couriers\PickupPointLists;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The two public, sessionless, read-only endpoints of the checkout picker (COUR-2a, courier-integration-design.md §4.3):
 *   GET /api/shipping/settlements?carrier=&kind=&q=              settlements where the courier has an offerable point
 *   GET /api/shipping/pickup-points?carrier=&kind=&settlement=   those points
 * They read our own tables only (a courier is never called), carry no cart and no identity, and answer the same to every visitor, so
 * a good answer is `public, max-age=300` (the lists change at most nightly). A courier that is not installed and enabled answers 404.
 */
final class PickupPointController extends Controller
{
    private const CACHE = 'public, max-age=300';

    public function __construct(private readonly PickupPointLists $lists) {}

    public function settlements(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'carrier' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/D'],
            'kind' => ['required', 'string', 'in:'.implode(',', OfferablePickupPoints::KINDS)],
            'q' => ['required', 'string', 'max:200'],
        ]);

        // trimmed before it is judged: «  а » is one character, not three
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';

        if ($validator->fails() || mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            return response()->json(['message' => __('checkout.pickup.bad_request')], 422);
        }

        try {
            $found = $this->lists->settlements((string) $request->query('carrier'), (string) $request->query('kind'), $q);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        return $found === null ? $this->notFound() : $this->ok($found);
    }

    public function points(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'carrier' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/D'],
            'kind' => ['required', 'string', 'in:'.implode(',', OfferablePickupPoints::KINDS)],
            'settlement' => ['required', 'string', 'max:130'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => __('checkout.pickup.bad_request')], 422);
        }

        try {
            $found = $this->lists->points((string) $request->query('carrier'), (string) $request->query('kind'), (string) $request->query('settlement'));
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        return $found === null ? $this->notFound() : $this->ok($found);
    }

    /** @param list<array<string, mixed>> $data */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', self::CACHE);
    }

    /** The class only (a message may carry a query); the page falls back to the free-text field on any non-200. */
    private function failed(Throwable $e): JsonResponse
    {
        Log::warning('Pickup point list failed.', ['exception' => $e::class]);

        return response()->json(['message' => __('checkout.pickup.unavailable')], 503);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => __('checkout.pickup.unavailable')], 404);
    }
}
