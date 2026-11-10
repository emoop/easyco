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
 *  - ONLY percent-off campaigns are made here (FIXED_ITEMS lists and the two system lists are never touched: a system list is refused). Every campaign gets the same fixed priority band, BELOW «Manual Sale» (1000):
 *    there is no priority field. Two campaigns that match the same product are decided by the resolver (equal priority: the newest list wins), and two lists with the IDENTICAL scope set at the same priority are
 *    refused by the database (UNIQUE(priority, scope_signature)) — shown as an error on the scope.
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

        try {
            $list = PriceList::create(
                name: $input['name'],
                mode: PriceListMode::PERCENTAGE_OFF_REGULAR,
                priority: self::CAMPAIGN_PRIORITY,
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

        $this->transactionally(function () use ($list, $scopes): void {
            SortPriceTriggers::muted(function () use ($list, $scopes): void {
                // the list and its scope rows in ONE insert with the FINAL signature (a list saved first would take the universal one and collide with another universal list at this priority)
                $this->lists->saveWithScopes($list, array_map(static fn (array $scope): PriceListScope => new PriceListScope(null, '', $scope['kind'], $scope['reference']), $scopes));
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

        $this->transactionally(function () use ($list, $oldRows, $newRows, $before, $after): void {
            SortPriceTriggers::muted(function () use ($list, $oldRows, $newRows): void {
                $this->lists->save($list);
                $this->scopes->replaceForPriceList((string) $list->id(), array_map(static fn (array $row): PriceListScope => new PriceListScope(null, (string) $list->id(), PriceListScopeType::from($row[0]), $row[1]), $newRows));
            });

            foreach ($after as $field => $new) {
                if ($before[$field] !== $new) {
                    $this->audit->logFieldChanged(self::ENTITY, (string) $list->id(), $field, $before[$field], $new);
                }
            }
        });

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
     * ONE transaction. The unique index UNIQUE(priority, scope_signature) refuses two lists with the identical scope set at the same priority: SQLSTATE 23000 + the driver code (MySQL 1062, SQLite 19), never a
     * message match (CLAUDE.md rule 3) — shown as an error on the scope.
     *
     * @throws PriceListInvalidException
     */
    private function transactionally(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            $info = $exception->errorInfo ?? [];

            if (($info[0] ?? null) === '23000' && in_array((int) ($info[1] ?? 0), [1062, 19], true)) {
                throw new PriceListInvalidException(['scopes' => [__('price_lists.errors.scope_taken')]]);
            }

            throw $exception;
        }
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
