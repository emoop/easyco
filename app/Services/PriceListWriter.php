<?php

namespace App\Services;

use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceSort\SortPriceRefreshDispatcher;
use App\Services\PriceSort\SortPriceTriggers;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\PriceListScopeRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\PriceListSignature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * P6b: the ONE write path of the admin price-list CAMPAIGN form (a percent-off list with dates and scopes). A Filament page never writes a price list row: it hands the form's data to create() / update() /
 * setActive() here, which checks the typed text, builds or changes the DOMAIN entity (PriceList::create() / updateCampaign()), saves it AND its scope rows through the repositories in ONE transaction, writes the audit
 * entries in the same transaction and, only after the commit, queues the sort-price refresh and fires the hooks.
 *
 *  - ONLY percent-off campaigns are made here (FIXED_ITEMS lists and the two system lists are never touched: a system list is refused). Every campaign lives in the campaign band 1…999, BELOW «Manual Sale» (1000);
 *    there is no priority field — the WRITER picks it (P6b.1): the lowest number ≥ 500 that is free for the campaign's FINAL scope signature (the first campaign on a scope gets 500, the next one on the same scope 501…),
 *    else the highest free number below 500, else the scope error (band full). Any number of campaigns may share a scope. Two campaigns that match the same product are decided by the resolver
 *    (priority DESC, id DESC: the newest list wins). The unique index UNIQUE(priority, scope_signature) stays the last guard against a race: one retry with a fresh read, then the scope error.
 *  - SORT PRICES (P6a): while the writer saves, the model-event triggers are MUTED (SortPriceTriggers::muted()): the list row is saved before its scope rows, so a trigger would queue the «unscoped list → every
 *    product» pass in the middle of a create. Instead ONE explicit refresh is queued after the commit: the products the old scopes touched UNITED with the products the new scopes touch (every product only when a
 *    side has no scope).
 *  - AUDIT: entity `price_list`; create → one `created` entry; update → ONE entry per field that really changed (name, percentage, valid_from, valid_until, replaces_manual_sale, status, scopes as a sorted
 *    list); a write that changes nothing writes nothing (no save, no entry, no hook).
 *  - HOOKS (after commit, CONTAINED like PromotionWriter: a throwing listener is logged and never undoes or breaks the write): pricing.price_list.created / .updated (with the `$before` snapshot) / .activated / .deactivated.
 * Dates are typed in the SHOP time zone (minute precision) and stored in UTC.
 */
final class PriceListWriter
{
    public const ENTITY = 'price_list';

    /** The priority of every campaign made here: below «Manual Sale» (1000), above «Regular Prices» (0). */
    public const CAMPAIGN_PRIORITY = 500;

    public function __construct(
        private readonly PriceListRepository $lists,
        private readonly PriceListScopeRepository $scopes,
        private readonly CatalogScopeLookup $lookup,
        private readonly SortPriceRefreshDispatcher $refresh,
        private readonly ActivityLogger $audit,
        private readonly StoreTimezone $timezone,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  name, percentage, valid_from, valid_until, is_active, replaces_manual_sale, scopes (list of [type, reference]), confirm_all_products
     *
     * @throws PriceListInvalidException
     */
    public function create(array $data): PriceList
    {
        $errors = [];
        $input = $this->input($data, $errors);
        $scopes = $this->parseScopes($data['scopes'] ?? [], $errors);
        $this->requireConfirmationWhenUnscoped($scopes, $data, $errors);

        if ($errors !== []) {
            throw new PriceListInvalidException($errors);
        }

        // the scope entities take their ids once: every attempt gets its own
        $scopeRows = static fn (): array => array_map(static fn (array $scope): PriceListScope => new PriceListScope(null, '', $scope['kind'], $scope['reference']), $scopes);
        $signature = PriceListSignature::forScopes($scopeRows())->value();
        $list = null;

        // every attempt builds a FRESH entity: the priority is read inside the transaction (a retry after a lost race reads it again)
        $this->transactionally(function () use (&$list, $input, $data, $scopeRows, $signature): void {
            $priority = $this->freePriority($signature, null) ?? throw new PriceListInvalidException(['scopes' => [__('price_lists.errors.scope_band_full')]]);

            try {
                $list = PriceList::create(
                    name: $input['name'],
                    mode: PriceListMode::PERCENTAGE_OFF_REGULAR,
                    priority: $priority,
                    validFrom: $input['validFrom'],
                    validUntil: $input['validUntil'],
                    percentageBasisPoints: $input['basisPoints'],
                    replacesManualSale: $input['replaces'],
                );
            } catch (InvalidArgumentException $exception) {
                throw new PriceListInvalidException(['name' => [$exception->getMessage()]]);
            }

            if (($data['is_active'] ?? true) === false) {
                $list->deactivate();
            }

            SortPriceTriggers::muted(function () use ($list, $scopeRows): void {
                // the list and its scope rows in ONE insert with the FINAL signature (a list saved first would take the universal one and collide with another universal list at this priority)
                $this->lists->saveWithScopes($list, $scopeRows());
            });

            $this->audit->logCreated(self::ENTITY, (string) $list->id());
        });

        $this->queueRefresh(null, $this->rowsOf($scopes));
        $this->fireContained('pricing.price_list.created', $list);

        return $list;
    }

    /**
     * @param  array<string, mixed>  $data  the same keys as create(); an unchanged form changes nothing
     *
     * @throws PriceListInvalidException
     */
    public function update(string $id, array $data): PriceList
    {
        $list = $this->campaign($id);

        $errors = [];
        $input = $this->input($data, $errors);
        $scopes = $this->parseScopes($data['scopes'] ?? [], $errors);
        $this->requireConfirmationWhenUnscoped($scopes, $data, $errors);

        if ($errors !== []) {
            throw new PriceListInvalidException($errors);
        }

        $oldRows = $this->storedRows($id);
        $before = $this->snapshot($list, $oldRows);

        try {
            $list->updateCampaign($input['name'], $input['basisPoints'], $input['validFrom'], $input['validUntil'], $input['replaces']);
        } catch (InvalidArgumentException $exception) {
            throw new PriceListInvalidException(['name' => [$exception->getMessage()]]);
        }

        ($data['is_active'] ?? $list->isActive()) ? $list->activate() : $list->deactivate();

        $newRows = $this->rowsOf($scopes);
        $after = $this->snapshot($list, $newRows);

        // a write that changes nothing writes nothing: no save, no entry, no refresh, no hook
        if ($after === $before) {
            return $list;
        }

        $originalPriority = $list->priority();
        $target = $originalPriority;
        $scopesChanged = $before['scopes'] !== $after['scopes'];
        $newSignature = PriceListSignature::forScopes(array_map(static fn (array $row): PriceListScope => new PriceListScope(null, '', PriceListScopeType::from($row[0]), $row[1]), $newRows))->value();

        $this->transactionally(function () use ($list, $newRows, $before, $after, $originalPriority, &$target, $scopesChanged, $newSignature): void {
            // the priority only moves when the scope changed AND (current priority, new signature) is taken by another list; then the list goes to a free priority by the same rule as a new one
            $target = $originalPriority;

            if ($scopesChanged && $this->isTaken($newSignature, $originalPriority, (string) $list->id())) {
                $target = $this->freePriority($newSignature, (string) $list->id()) ?? throw new PriceListInvalidException(['scopes' => [__('price_lists.errors.scope_band_full')]]);
            }

            SortPriceTriggers::muted(function () use ($list, $newRows, $originalPriority, $target): void {
                $this->lists->save($list);
                $this->scopes->replaceForPriceList((string) $list->id(), array_map(static fn (array $row): PriceListScope => new PriceListScope(null, (string) $list->id(), PriceListScopeType::from($row[0]), $row[1]), $newRows), $target === $originalPriority ? null : $target);
            });

            foreach ($after as $field => $new) {
                if ($before[$field] !== $new) {
                    $this->audit->logFieldChanged(self::ENTITY, (string) $list->id(), $field, $before[$field], $new);
                }
            }
        });

        if ($target !== $originalPriority) {
            $list->moveToPriority($target);
        }

        $this->queueRefresh($oldRows, $newRows);
        $this->fireContained('pricing.price_list.updated', $list, $before);

        if ($before['status'] !== $after['status']) {
            $this->fireStatusHook($list);
        }

        return $list;
    }

    /**
     * Switches a campaign on or off; switching to the state it already has writes nothing.
     *
     * @throws PriceListInvalidException
     */
    public function setActive(string $id, bool $active): PriceList
    {
        $list = $this->campaign($id);

        if ($list->isActive() === $active) {
            return $list;
        }

        $this->transactionally(function () use ($list, $active): void {
            SortPriceTriggers::muted(function () use ($list, $active): void {
                $active ? $list->activate() : $list->deactivate();
                $this->lists->save($list);
            });

            $this->audit->logFieldChanged(self::ENTITY, (string) $list->id(), 'status', $active ? 'inactive' : 'active', $active ? 'active' : 'inactive');
        });

        $rows = $this->storedRows($id);
        $this->queueRefresh($rows, $rows);
        $this->fireStatusHook($list);

        return $list;
    }

    // ---- reading and checking ------------------------------------------------------------------------------------

    /** @throws PriceListInvalidException */
    private function campaign(string $id): PriceList
    {
        $list = $this->lists->findById($id);

        if ($list === null) {
            throw new PriceListInvalidException(['name' => [__('price_lists.errors.not_found')]]);
        }

        if ($list->isSystem() || $list->mode() !== PriceListMode::PERCENTAGE_OFF_REGULAR) {
            throw new PriceListInvalidException(['name' => [__('price_lists.errors.system_list')]]);
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     * @return array{name: string, basisPoints: int, validFrom: ?DateTimeImmutable, validUntil: ?DateTimeImmutable, replaces: bool}
     */
    private function input(array $data, array &$errors): array
    {
        $name = trim(is_scalar($data['name'] ?? null) ? (string) $data['name'] : '');

        if ($name === '') {
            $errors['name'][] = __('price_lists.errors.name_required');
        } elseif (mb_strlen($name) > 255) {
            $errors['name'][] = __('price_lists.errors.name_too_long');
        }

        $basisPoints = PromotionWriter::parsePercent($data['percentage'] ?? null);

        // a discount of 100 % (a free product) or of 0 is not a campaign
        if ($basisPoints === null || $basisPoints >= 10000) {
            $errors['percentage'][] = __('price_lists.errors.percentage_invalid');
            $basisPoints = 1;
        }

        $from = $this->date($data['valid_from'] ?? null, 'valid_from', $errors);
        $until = $this->date($data['valid_until'] ?? null, 'valid_until', $errors);

        if ($from !== null && $until !== null && $until <= $from) {
            $errors['valid_until'][] = __('price_lists.errors.until_not_after_from');
        }

        return ['name' => $name, 'basisPoints' => $basisPoints, 'validFrom' => $from, 'validUntil' => $until, 'replaces' => (bool) ($data['replaces_manual_sale'] ?? false)];
    }

    /**
     * A date typed in the SHOP time zone, to the minute, as the UTC instant the database stores.
     *
     * @param  array<string, list<string>>  $errors
     */
    private function date(mixed $raw, string $field, array &$errors): ?DateTimeImmutable
    {
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        if ($text === '') {
            return null;
        }

        try {
            $local = new DateTimeImmutable($text, $this->timezone->zone());
        } catch (Throwable) {
            $errors[$field][] = __('price_lists.errors.date_invalid');

            return null;
        }

        $utc = $local->setTime((int) $local->format('H'), (int) $local->format('i'), 0)->setTimezone(new DateTimeZone((string) config('app.timezone', 'UTC')));

        // the column is a TIMESTAMP: it ends on 2038-01-19; a later date would be a database error, so it is a field error
        if ($utc >= new DateTimeImmutable('2038-01-01 00:00:00', new DateTimeZone('UTC'))) {
            $errors[$field][] = __('price_lists.errors.date_invalid');

            return null;
        }

        return $utc;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{kind: PriceListScopeType, reference: string}>
     */
    private function parseScopes(mixed $rows, array &$errors): array
    {
        $out = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $kind = PriceListScopeType::tryFrom(is_scalar($row['type'] ?? null) ? (string) $row['type'] : '');
            $reference = trim(is_scalar($row['reference'] ?? null) ? (string) $row['reference'] : '');

            if ($kind === null || ! in_array($kind, CatalogScopeLookup::KINDS, true) || $reference === '' || ! $this->lookup->exists($kind, $reference)) {
                $errors['scopes'][] = __('price_lists.errors.scope_invalid');

                continue;
            }

            $key = $kind->value.'|'.$reference;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = ['kind' => $kind, 'reference' => $reference];
        }

        return $out;
    }

    /**
     * A campaign with NO scope applies to EVERY product: the save needs the explicit confirmation (the form's switch; its value is not stored).
     *
     * @param  list<array{kind: PriceListScopeType, reference: string}>  $scopes
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private function requireConfirmationWhenUnscoped(array $scopes, array $data, array &$errors): void
    {
        if ($scopes === [] && ! ($data['confirm_all_products'] ?? false) && ! isset($errors['scopes'])) {
            $errors['confirm_all_products'][] = __('price_lists.errors.confirm_all_products');
        }
    }

    // ---- scopes, snapshots ---------------------------------------------------------------------------------------

    /**
     * @param  list<array{kind: PriceListScopeType, reference: string}>  $scopes
     * @return list<array{0: string, 1: string}> [kind, reference]
     */
    private function rowsOf(array $scopes): array
    {
        return array_map(static fn (array $scope): array => [$scope['kind']->value, $scope['reference']], $scopes);
    }

    /** @return list<array{0: string, 1: string}> the stored scope rows of a list: [kind, reference] */
    private function storedRows(string $listId): array
    {
        return DB::table('pricing_price_list_scopes')->where('price_list_id', $listId)->orderBy('id')->get(['scope_type', 'scope_reference_id'])
            ->map(static fn ($row): array => [(string) $row->scope_type, (string) $row->scope_reference_id])->all();
    }

    /**
     * The values the audit compares, by the FORM'S field names, as short readable strings (dates in the shop time zone, booleans 1 / 0, the scopes as one sorted compact list).
     *
     * @param  list<array{0: string, 1: string}>  $rows
     * @return array<string, string>
     */
    private function snapshot(PriceList $list, array $rows): array
    {
        $date = fn (?DateTimeImmutable $at): string => $at === null ? '' : $at->setTimezone($this->timezone->zone())->format('Y-m-d H:i');
        $keys = array_values(array_unique(array_map(static fn (array $row): string => $row[0].':'.$row[1], $rows)));
        sort($keys);

        return [
            'name' => $list->name(),
            'percentage' => PromotionWriter::percentText((int) $list->percentageBasisPoints()),
            'valid_from' => $date($list->validFrom()),
            'valid_until' => $date($list->validUntil()),
            'replaces_manual_sale' => $list->replacesManualSale() ? '1' : '0',
            'status' => $list->isActive() ? 'active' : 'inactive',
            'scopes' => implode(', ', $keys),
        ];
    }

    // ---- the transaction, the refresh, the hooks -----------------------------------------------------------------------

    /**
     * ONE transaction. The unique index UNIQUE(priority, scope_signature) is the last guard behind the free-priority choice: when two admins save at the same moment and it still refuses (SQLSTATE 23000 + the driver
     * code, MySQL 1062 / SQLite 19, never a message match — CLAUDE.md rule 3), the whole write is retried ONCE (the closure reads the free priority again); a second refusal is the scope error.
     *
     * @throws PriceListInvalidException
     */
    private function transactionally(callable $write): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction($write);

                return;
            } catch (QueryException $exception) {
                $info = $exception->errorInfo ?? [];

                if (($info[0] ?? null) !== '23000' || ! in_array((int) ($info[1] ?? 0), [1062, 19], true)) {
                    throw $exception;
                }

                if ($attempt >= 2) {
                    throw new PriceListInvalidException(['scopes' => [__('price_lists.errors.scope_band_full')]]);
                }
            }
        }
    }

    /** Is the pair (priority, signature) used by ANOTHER list (active or not: the unique index does not care)? */
    private function isTaken(string $signature, int $priority, ?string $exceptId): bool
    {
        return DB::table('pricing_price_lists')->where('scope_signature', $signature)->where('priority', $priority)->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->exists();
    }

    /**
     * The campaign priority for a scope signature: the lowest number ≥ 500 that no other list uses with this signature, else the highest free number below 500; null when the whole band 1…999 is used.
     */
    private function freePriority(string $signature, ?string $exceptId): ?int
    {
        $used = DB::table('pricing_price_lists')->where('scope_signature', $signature)->whereBetween('priority', [1, 999])->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))->pluck('priority')->map(fn ($p): int => (int) $p)->flip()->all();

        for ($priority = self::CAMPAIGN_PRIORITY; $priority <= 999; $priority++) {
            if (! isset($used[$priority])) {
                return $priority;
            }
        }

        for ($priority = self::CAMPAIGN_PRIORITY - 1; $priority >= 1; $priority--) {
            if (! isset($used[$priority])) {
                return $priority;
            }
        }

        return null;
    }

    /**
     * Queues the sort-price refresh ONCE: the products the OLD scopes touched UNITED with the products the NEW scopes touch. A side with no scope touches EVERY product (the full pass, in queued chunks); a list
     * that did not exist before ($old = null, a create) touched none.
     *
     * @param  list<array{0: string, 1: string}>|null  $old
     * @param  list<array{0: string, 1: string}>  $new
     */
    private function queueRefresh(?array $old, array $new): void
    {
        $touchedNew = $this->refresh->productsOfScopes($new);
        $touchedOld = $old === null ? [] : $this->refresh->productsOfScopes($old);

        if ($touchedNew === null || $touchedOld === null) {
            $this->refresh->all();

            return;
        }

        $ids = array_values(array_unique(array_merge($touchedNew, $touchedOld)));
        sort($ids, SORT_NUMERIC);

        $this->refresh->products($ids);
    }
    private function fireContained(string $hook, mixed ...$args): void
    {
        try {
            Hook::fire($hook, ...$args);
        } catch (Throwable $exception) {
            Log::error('A price-list hook listener failed; the price-list write stands.', ['hook' => $hook, 'exception' => $exception->getMessage()]);
        }
    }

    private function fireStatusHook(PriceList $list): void
    {
        $this->fireContained($list->isActive() ? 'pricing.price_list.activated' : 'pricing.price_list.deactivated', $list);
    }
}
