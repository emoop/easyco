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
            // the courier is needed for office / locker / pickup; `address` asks for every settlement and needs none
            'carrier' => ['required_unless:kind,address', 'nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/D'],
            'kind' => ['required', 'string', 'in:'.implode(',', [...OfferablePickupPoints::KINDS, 'address'])],
            'q' => ['required', 'string', 'max:200'],
        ]);

        // trimmed before it is judged: «  а » is one character, not three
        $q = is_string($request->query('q')) ? trim($request->query('q')) : '';

        if ($validator->fails() || mb_strlen($q) < 2 || mb_strlen($q) > 60) {
            return response()->json(['message' => __('checkout.pickup.bad_request')], 422);
        }

        try {
            $found = $this->lists->settlements(is_string($request->query('carrier')) ? $request->query('carrier') : null, (string) $request->query('kind'), $q);
        } catch (Throwable $e) {
            return $this->failed($e);
        }

        // COUR-2a.6: only an EMPTY answer carries `meta` (a place that exists but has no point of this kind is not a place that was not found)
        $meta = $found === [] ? $this->lists->emptyMeta(is_string($request->query('carrier')) ? $request->query('carrier') : null, (string) $request->query('kind'), $q) : null;

        return $found === null ? $this->notFound() : $this->ok($found, $meta);
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

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array<string, mixed>|null  $meta
     */
    private function ok(array $data, ?array $meta = null): JsonResponse
    {
        return response()->json($meta === null ? ['data' => $data] : ['data' => $data, 'meta' => $meta])->header('Cache-Control', self::CACHE);
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
