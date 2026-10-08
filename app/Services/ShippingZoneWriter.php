<?php

namespace App\Services;

use App\Rules\KnownCountryCode;
use App\Services\Exceptions\ShippingZoneInUseException;
use App\Services\Exceptions\ShippingZoneInvalidException;
use App\Services\Exceptions\ShippingZoneNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Exceptions\InvalidShippingZoneException;
use EasyCo\Shipping\Matching\PostcodeNormalizer;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create / update / delete a shipping ZONE (shipping-domain-design.md §12.3.2, §12.6) — the only way the admin
 * writes a zone; no Filament form writes a shipping row itself. Each call:
 *
 *  1. VALIDATES — first the bounds the domain does not know (length, plain single-line text, a bounded number of
 *     entries, a country from the known list), then THROUGH THE ENTITY (ShippingZone::create / update), whose
 *     Invalid… exception is the last word. Every refusal is a ShippingZoneInvalidException of TRANSLATED messages
 *     keyed by the form's field names; a raw message or a SQL error never reaches a screen. Nothing is written.
 *  2. SAVES through the repository, and writes EXACTLY ONE ActivityLogger entry (entity `shipping_zone`) in the
 *     same transaction: create -> logCreated; delete -> logDeleted with a JSON snapshot; update -> ONE
 *     logFieldChanged. THE DESIGN SAYS `field = null` FOR UPDATE, BUT THE REAL SIGNATURE TAKES A NON-NULL FIELD
 *     (`logFieldChanged(type, id, string $field, ?old, ?new)`) and ActivityLogger must not change: the fixed field
 *     name is `zone`, old/new are compact JSON snapshots. (Like every field change, it is written only while
 *     `admin.activity_log_enabled` is on; a delete is always written.)
 *  3. FIRES ONE HOOK after the transaction has committed, never inside it: `shipping.zone.created (ShippingZone)`,
 *     `shipping.zone.updated (ShippingZone, array $before)`, `shipping.zone.deleted (array $snapshot)`. A
 *     refusal or a failed write fires nothing and logs nothing.
 *
 * A zone is created at the END of the match order (sortOrder = highest + 1); an update NEVER changes sortOrder —
 * the order changes only through ShippingZoneReorderer. A zone that still has methods is not deleted
 * (ShippingZoneInUseException); the restrict foreign key is only the backstop.
 *
 * NO CACHE IS FLUSHED (§12.6): ShippingQuoteService recomputes the zone, the methods and every amount on every
 * call, so the next quote already reflects the change; a handle issued earlier fails on the amount by design.
 *
 * No permission check here, like every app service: the screen that calls this is what is authorized
 * (shipping_manage), and the actor is resolved inside ActivityLogger from the panel guard.
 */
final class ShippingZoneWriter
{
    /** Entries per list (settlements, postcodes), so the stored JSON stays bounded. */
    public const MAX_ENTRIES = 500;

    public const SETTLEMENT_MAX_LENGTH = 255;

    /** A postcode as typed, before it is normalised to 2-12 characters. */
    public const POSTCODE_INPUT_MAX_LENGTH = 20;

    public const ENTITY = 'shipping_zone';

    public function __construct(
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingMethodRepository $methods,
        private readonly ActivityLogger $audit,
    ) {
    }

    /**
     * @param  array<int, mixed>  $countryCodes
     * @param  array<int, mixed>  $settlementNames
     * @param  array<int, mixed>  $postcodes
     *
     * @throws ShippingZoneInvalidException
     */
    public function create(string $name, array $countryCodes, array $settlementNames, array $postcodes): ShippingZone
    {
        $input = $this->clean($name, $countryCodes, $settlementNames, $postcodes);

        $zone = DB::transaction(function () use ($input): ShippingZone {
            $next = 0;

            foreach ($this->zones->allOrdered() as $existing) {
                $next = max($next, $existing->sortOrder() + 1);
            }

            $zone = $this->guarded(fn (): ShippingZone => ShippingZone::create($input['name'], $next, $input['countries'], $input['settlements'], $input['postcodes']));

            $this->zones->save($zone);
            $this->audit->logCreated(self::ENTITY, (string) $zone->id());

            return $zone;
        });

        Hook::fire('shipping.zone.created', $zone);

        return $zone;
    }

    /**
     * NEVER changes sortOrder. An update that changes nothing writes nothing: no save, no audit entry, no hook.
     *
     * @param  array<int, mixed>  $countryCodes
     * @param  array<int, mixed>  $settlementNames
     * @param  array<int, mixed>  $postcodes
     *
     * @throws ShippingZoneInvalidException
     * @throws ShippingZoneNotFoundException
     */
    public function update(string $id, string $name, array $countryCodes, array $settlementNames, array $postcodes): ShippingZone
    {
        $input = $this->clean($name, $countryCodes, $settlementNames, $postcodes);

        $result = DB::transaction(function () use ($id, $input): ?array {
            $zone = $this->zones->findById($id) ?? throw new ShippingZoneNotFoundException();
            $before = self::snapshot($zone);

            $this->guarded(fn () => $zone->update($input['name'], $zone->sortOrder(), $input['countries'], $input['settlements'], $input['postcodes']));

            $after = self::snapshot($zone);

            if ($after === $before) {
                return ['zone' => $zone, 'before' => null];
            }

            $this->zones->save($zone);
            $this->audit->logFieldChanged(self::ENTITY, (string) $zone->id(), 'zone', self::json($before), self::json($after));

            return ['zone' => $zone, 'before' => $before];
        });

        if ($result['before'] !== null) {
            Hook::fire('shipping.zone.updated', $result['zone'], $result['before']);
        }

        return $result['zone'];
    }

    /**
     * @throws ShippingZoneNotFoundException
     * @throws ShippingZoneInUseException the zone still has methods
     */
    public function delete(string $id): void
    {
        $snapshot = DB::transaction(function () use ($id): array {
            $zone = $this->zones->findById($id) ?? throw new ShippingZoneNotFoundException();
            $snapshot = self::snapshot($zone);

            $count = count($this->methods->forZone($id));

            if ($count > 0) {
                throw new ShippingZoneInUseException($zone->name(), $count);
            }

            try {
                $this->zones->delete($id);
            } catch (QueryException $exception) {
                // The restrict foreign key is the backstop: a method was added between the check and the delete.
                if (self::isForeignKeyRefusal($exception)) {
                    throw new ShippingZoneInUseException($zone->name(), max(1, count($this->methods->forZone($id))));
                }

                throw $exception;
            }

            $this->audit->logDeleted(self::ENTITY, $id, $snapshot);

            return $snapshot;
        });

        Hook::fire('shipping.zone.deleted', $snapshot);
    }

    /**
     * The compact, stable picture of a zone that the audit entries and the hooks carry.
     *
     * @return array{id: ?string, name: string, sort_order: int, countries: list<string>, settlements: ?list<string>, postcodes: ?list<string>}
     */
    public static function snapshot(ShippingZone $zone): array
    {
        return [
            'id' => $zone->id(),
            'name' => $zone->name(),
            'sort_order' => $zone->sortOrder(),
            'countries' => $zone->countryCodes(),
            'settlements' => $zone->settlementNames(),
            'postcodes' => $zone->postcodes(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ---- validation ----------------------------------------------------------------------------------------

    /**
     * The bounds the domain does not know, with TRANSLATED messages by field. Everything the entity decides itself
     * (a postcode that cannot be normalised, a duplicate after normalisation, a duplicate country) is ALSO checked
     * here so the message names the right entry; the entity still runs afterwards and has the last word.
     *
     * @param  array<int, mixed>  $countryCodes
     * @param  array<int, mixed>  $settlementNames
     * @param  array<int, mixed>  $postcodes
     * @return array{name: string, countries: list<string>, settlements: ?list<string>, postcodes: ?list<string>}
     */
    private function clean(string $name, array $countryCodes, array $settlementNames, array $postcodes): array
    {
        $errors = [];
        $label = static fn (string $field): string => __('shipping.zones.fields.'.$field);

        // name
        $name = trim($name);

        if ($name === '') {
            $errors['name'][] = __('shipping.zones.errors.name_required');
        } elseif (mb_strlen($name) > ShippingZone::NAME_MAX_LENGTH) {
            $errors['name'][] = __('shipping.zones.errors.too_long', ['max' => ShippingZone::NAME_MAX_LENGTH]);
        } elseif (! self::isPlain($name)) {
            $errors['name'][] = __('validation.plain_text', ['attribute' => $label('name')]);
        }

        // countries
        $countries = [];

        if ($countryCodes === []) {
            $errors['country_codes'][] = __('shipping.zones.errors.countries_required');
        }

        foreach (array_values($countryCodes) as $code) {
            $normalized = KnownCountryCode::normalize($code);

            if (! is_string($normalized) || ! \App\Settings\CountryNames::isKnownCode($normalized)) {
                $errors['country_codes'][] = __('shipping.zones.errors.country_unknown', ['value' => self::shown($code)]);

                continue;
            }

            if (isset($countries[$normalized])) {
                $errors['country_codes'][] = __('shipping.zones.errors.duplicate', ['value' => $normalized]);

                continue;
            }

            $countries[$normalized] = true;
        }

        // settlement names — stored AS ENTERED (trimmed)
        $settlements = [];

        if (count($settlementNames) > self::MAX_ENTRIES) {
            $errors['settlement_names'][] = __('shipping.zones.errors.too_many', ['max' => self::MAX_ENTRIES]);
        } else {
            $seen = [];

            foreach (array_values($settlementNames) as $entry) {
                if (! is_string($entry) || trim($entry) === '') {
                    $errors['settlement_names'][] = __('shipping.zones.errors.settlement_empty');

                    continue;
                }

                $entry = trim($entry);

                if (mb_strlen($entry) > self::SETTLEMENT_MAX_LENGTH) {
                    $errors['settlement_names'][] = __('shipping.zones.errors.too_long', ['max' => self::SETTLEMENT_MAX_LENGTH]);
                } elseif (! self::isPlain($entry)) {
                    $errors['settlement_names'][] = __('validation.plain_text', ['attribute' => $label('settlement_names')]);
                } elseif (isset($seen[$entry])) {
                    $errors['settlement_names'][] = __('shipping.zones.errors.duplicate', ['value' => self::shown($entry)]);
                } else {
                    $seen[$entry] = true;
                    $settlements[] = $entry;
                }
            }
        }

        // postcodes — pre-checked, then normalised by the SAME normaliser the matcher and the entity use
        $normalizedPostcodes = [];

        if (count($postcodes) > self::MAX_ENTRIES) {
            $errors['postcodes'][] = __('shipping.zones.errors.too_many', ['max' => self::MAX_ENTRIES]);
        } else {
            $seen = [];

            foreach (array_values($postcodes) as $entry) {
                if (! is_string($entry) || trim($entry) === '') {
                    $errors['postcodes'][] = __('shipping.zones.errors.postcode_invalid', ['value' => self::shown($entry)]);

                    continue;
                }

                if (mb_strlen($entry) > self::POSTCODE_INPUT_MAX_LENGTH) {
                    $errors['postcodes'][] = __('shipping.zones.errors.too_long', ['max' => self::POSTCODE_INPUT_MAX_LENGTH]);

                    continue;
                }

                if (! self::isPlain($entry)) {
                    $errors['postcodes'][] = __('validation.plain_text', ['attribute' => $label('postcodes')]);

                    continue;
                }

                $normalized = PostcodeNormalizer::normalize($entry);

                if (preg_match('/^[A-Z0-9-]{2,12}$/D', $normalized) !== 1) {
                    $errors['postcodes'][] = __('shipping.zones.errors.postcode_invalid', ['value' => self::shown($entry)]);
                } elseif (isset($seen[$normalized])) {
                    $errors['postcodes'][] = __('shipping.zones.errors.duplicate', ['value' => $normalized]);
                } else {
                    $seen[$normalized] = true;
                    $normalizedPostcodes[] = $normalized;
                }
            }
        }

        if ($errors !== []) {
            throw new ShippingZoneInvalidException($errors);
        }

        return [
            'name' => $name,
            'countries' => array_keys($countries),
            'settlements' => $settlements === [] ? null : $settlements,
            'postcodes' => $normalizedPostcodes === [] ? null : $normalizedPostcodes,
        ];
    }

    /**
     * Runs a domain call; the entity's Invalid… exception becomes a translated field error (the entity's own
     * English message is never shown). The pre-checks above make this a backstop.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private function guarded(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (InvalidShippingZoneException $exception) {
            $message = $exception->getMessage();

            $field = match (true) {
                str_contains($message, 'name') && ! str_contains($message, 'settlement') => 'name',
                str_contains($message, 'country') => 'country_codes',
                str_contains($message, 'settlement') => 'settlement_names',
                str_contains($message, 'postcode') => 'postcodes',
                default => 'name',
            };

            throw new ShippingZoneInvalidException([$field => [__('shipping.zones.errors.invalid')]]);
        }
    }

    /** A single line of plain text: no control characters, no bidirectional override (the project's PlainText rule). */
    private static function isPlain(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 0;
    }

    /** What a refusal quotes of a typed value: short, and only ever a string. */
    private static function shown(mixed $value): string
    {
        return is_string($value) ? mb_strimwidth($value, 0, 40, '…') : get_debug_type($value);
    }

    /** SQLSTATE 23000 + the driver's foreign-key code (MySQL 1451 on delete, SQLite 19) — never the message (CLAUDE.md rule 3). */
    private static function isForeignKeyRefusal(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000' && in_array((int) ($exception->errorInfo[1] ?? 0), [1451, 19], true);
    }
}
