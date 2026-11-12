<?php

namespace App\Services\MassEdit;

use App\Jobs\ProductMassEditChunk;
use App\Services\ActivityLogger;
use App\Services\PriceSort\SortPriceRefreshDispatcher;
use App\Services\PriceSort\SortPriceTriggers;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductCollectionRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductCollection;
use EasyCo\Catalog\ProductTag;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use Illuminate\Support\Facades\DB;

/**
 * P6d: the ONE write path of the product list's «Масови действия». The Filament action only hands over WHAT was chosen (a MassEditSelection) and the operation; this class checks the rules, does the work in chunks and
 * returns the counts, and the queued job (ProductMassEditChunk) calls the very same applyChunk().
 *
 *  - RULES (enforced here, never only in the UI): a set that is neither picked by hand nor «all matching» of an active filter / search is refused; more than MAX_PRODUCTS products are refused; an unknown value, a
 *    field that the Catalog settings switch off (season, tags) and the clearing of a REQUIRED article group are refused.
 *  - SIZE: up to SYNC_LIMIT products run in the request; above it ONE job per CHUNK products is queued (the notification of the Filament layer says how many products were accepted).
 *  - EVERYTHING GOES THROUGH THE CODE A MANUAL PRODUCT SAVE USES: categories and tags through ProductCategoryRepository / ProductTagRepository, season and article group through the Product aggregate
 *    (assignSeason() / assignProductGroup()) and ProductRepository::save(), the same audit entries as EditProduct (one `logFieldChanged` per product and field). Nothing is written with raw SQL except the reset's
 *    reads. The P6a / P6c model-event triggers are MUTED per chunk and ONE SortPriceRefreshDispatcher call per chunk refreshes the products whose price can have changed (a category, tag or season is a price-list
 *    scope; the article group is not).
 *  - IDEMPOTENT: adding what is there, removing what is absent, setting the value that is already set: «unchanged», nothing written, nothing audited. A product that no longer exists is «skipped».
 *  - THE RESET deletes ONLY the items of the system list «Manual Sale» (the variation-level items of the products' variations, soft-deleted ones included, and the product-level item): the regular prices and every
 *    campaign item are never read for deletion. By the owner's decision it writes NO audit entry and has no restore.
 */
final class ProductMassEditor
{
    public const MAX_PRODUCTS = 5000;

    public const SYNC_LIMIT = 200;

    public const CHUNK = 100;

    public const ADD_CATEGORY = 'add_category';

    public const REMOVE_CATEGORY = 'remove_category';

    public const ADD_TAG = 'add_tag';

    public const REMOVE_TAG = 'remove_tag';

    public const ADD_COLLECTION = 'add_collection';

    public const REMOVE_COLLECTION = 'remove_collection';

    public const SET_SEASON = 'set_season';

    public const SET_GROUP = 'set_group';

    public const RESET_MANUAL_PRICES = 'reset_manual_prices';

    public const OPERATIONS = [self::ADD_CATEGORY, self::REMOVE_CATEGORY, self::ADD_TAG, self::REMOVE_TAG, self::ADD_COLLECTION, self::REMOVE_COLLECTION, self::SET_SEASON, self::SET_GROUP, self::RESET_MANUAL_PRICES];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly ProductTagRepository $tags,
        private readonly ProductCollectionRepository $collections,
        private readonly PriceListRepository $lists,
        private readonly PriceListItemRepository $items,
        private readonly ActivityLogger $audit,
        private readonly SortPriceRefreshDispatcher $refresh,
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /**
     * Checks the rules, then runs the operation: synchronously up to SYNC_LIMIT products, in queued chunks above it.
     *
     * @param  string|null  $value  the category / tag / season / group id; null clears a season or a group; unused by the reset
     * @throws MassEditRefused
     */
    public function start(string $operation, ?string $value, MassEditSelection $selection, ?string $actorStaffId = null): MassEditResult
    {
        $selection->assertAllowed();
        $this->assertValue($operation, $value);

        if ($selection->count() <= self::SYNC_LIMIT) {
            $result = new MassEditResult();

            foreach (array_chunk($selection->ids, self::CHUNK) as $chunk) {
                $result->merge($this->applyChunk($operation, $value, $chunk));
            }

            return $result;
        }

        foreach (array_chunk($selection->ids, self::CHUNK) as $chunk) {
            ProductMassEditChunk::dispatch($operation, $value, $chunk, $actorStaffId);
        }

        return new MassEditResult(queued: $selection->count());
    }

    /**
     * @throws MassEditRefused
     */
    public function assertValue(string $operation, ?string $value): void
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new MassEditRefused('unknown_value');
        }

        match ($operation) {
            self::ADD_CATEGORY, self::REMOVE_CATEGORY => $this->mustExist($value !== null && CategoryModel::query()->whereKey($value)->exists()),
            self::ADD_TAG, self::REMOVE_TAG => $this->mustBeOn('catalog.tags_field_enabled') ?? $this->mustExist($value !== null && TagModel::query()->whereKey($value)->exists()),
            self::ADD_COLLECTION, self::REMOVE_COLLECTION => $this->collectionsMustBeOn() ?? $this->mustExist($value !== null && CollectionModel::query()->whereKey($value)->exists()),
            self::SET_SEASON => $this->mustBeOn('catalog.season_field_enabled') ?? $this->mustExist($value === null || SeasonModel::query()->whereKey($value)->exists()),
            self::SET_GROUP => $this->assertGroup($value),
            self::RESET_MANUAL_PRICES => null,
        };
    }

    private function mustExist(bool $exists): void
    {
        $exists || throw new MassEditRefused('unknown_value');
    }

    /** @return null always: `null` lets the caller chain the next check with `??` */
    private function mustBeOn(string $switchKey): mixed
    {
        $this->settings->get($switchKey) !== '0' || throw new MassEditRefused('field_off');

        return null;
    }

    /** P6e-1: the collections switch is ON only for the literal '1' (default OFF): the opposite of the season / tags switches. @return null always, so the caller can chain `??` */
    private function collectionsMustBeOn(): mixed
    {
        $this->settings->get('catalog.collections_enabled') === '1' || throw new MassEditRefused('field_off');

        return null;
    }

    private function assertGroup(?string $value): void
    {
        $this->settings->get('catalog.product_group_field_enabled') !== '0' || throw new MassEditRefused('field_off');

        if ($value === null) {
            ($this->settings->get('catalog.product_group_required') ?? '0') === '1' && throw new MassEditRefused('group_required');

            return;
        }

        ProductGroupModel::query()->whereKey($value)->exists() || throw new MassEditRefused('unknown_value');
    }

    /**
     * ONE chunk (at most CHUNK products), in ONE transaction, with the sort-price triggers muted and ONE refresh queued after the commit.
     *
     * @param  list<string>  $ids
     */
    public function applyChunk(string $operation, ?string $value, array $ids): MassEditResult
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $existing = ProductModel::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $result = new MassEditResult(skipped: count($ids) - count($existing));

        if ($existing === []) {
            return $result;
        }

        $changed = [];

        DB::transaction(function () use ($operation, $value, $existing, $result, &$changed): void {
            SortPriceTriggers::muted(function () use ($operation, $value, $existing, $result, &$changed): void {
                $changed = match ($operation) {
                    self::ADD_CATEGORY => $this->pivots($this->categories, 'categories', $value, $existing, true),
                    self::REMOVE_CATEGORY => $this->pivots($this->categories, 'categories', $value, $existing, false),
                    self::ADD_TAG => $this->pivots($this->tags, 'tags', $value, $existing, true),
                    self::REMOVE_TAG => $this->pivots($this->tags, 'tags', $value, $existing, false),
                    self::ADD_COLLECTION => $this->pivots($this->collections, 'collections', $value, $existing, true),
                    self::REMOVE_COLLECTION => $this->pivots($this->collections, 'collections', $value, $existing, false),
                    self::SET_SEASON => $this->aggregateField($existing, 'season_id', $value),
                    self::SET_GROUP => $this->aggregateField($existing, 'product_group_id', $value),
                    self::RESET_MANUAL_PRICES => $this->resetManualPrices($existing, $result),
                };
            });
        });

        $result->changed += count($changed);
        $result->unchanged += count($existing) - count($changed);

        // a category, a tag, a collection (P6e-3 makes it a price scope), a season and a manual price are inputs of the stored sort price (price-list scopes, the Manual Sale list); the article group is not
        if ($changed !== [] && $operation !== self::SET_GROUP) {
            $this->refresh->products($changed);
        }

        return $result;
    }

    /**
     * Adds or removes ONE category / tag of the given products: the diff against the stored pivots (one grouped read), the same repository calls and the same audit entries as EditProduct::syncCategories().
     *
     * @param  ProductCategoryRepository|ProductTagRepository|ProductCollectionRepository  $repository
     * @param  list<string>  $productIds
     * @return list<string> the ids of the products that changed
     */
    private function pivots(object $repository, string $field, ?string $value, array $productIds, bool $add): array
    {
        $hasPivot = [];

        foreach ($repository->findByProductIds($productIds) as $pivot) {
            if ((match ($field) {
                'categories' => $pivot->categoryId(),
                'collections' => $pivot->collectionId(),
                default => $pivot->tagId(),
            }) === (string) $value) {
                $hasPivot[$pivot->productId()] = $pivot;
            }
        }

        $name = match ($field) {
            'categories' => CategoryModel::query()->find($value)?->name,
            'collections' => CollectionModel::query()->find($value)?->name,
            default => TagModel::query()->find($value)?->name,
        };
        $changed = [];

        foreach ($productIds as $productId) {
            $pivot = $hasPivot[$productId] ?? null;

            if ($add && $pivot === null) {
                $repository->save(match ($field) {
                    'categories' => new ProductCategory(id: null, productId: $productId, categoryId: (string) $value),
                    'collections' => new ProductCollection(id: null, productId: $productId, collectionId: (string) $value),
                    default => new ProductTag(id: null, productId: $productId, tagId: (string) $value),
                });
                $this->audit->logFieldChanged('product', $productId, $field, null, $name);
                $changed[] = $productId;
            } elseif (! $add && $pivot !== null) {
                $repository->remove((string) $pivot->id());
                $this->audit->logFieldChanged('product', $productId, $field, $name, null);
                $changed[] = $productId;
            }
        }

        return $changed;
    }

    /**
     * Sets (or clears, with null) the season or the article group through the Product aggregate and ProductRepository::save() — what EditProduct does — with the same audit entry per changed field.
     *
     * @param  list<string>  $productIds
     * @return list<string>
     */
    private function aggregateField(array $productIds, string $field, ?string $value): array
    {
        $changed = [];

        foreach ($productIds as $productId) {
            $product = $this->products->findById($productId);

            if ($product === null) {
                continue;
            }

            $old = $field === 'season_id' ? $product->seasonId() : $product->productGroupId();

            if ($old === $value) {
                continue;
            }

            $field === 'season_id' ? $product->assignSeason($value) : $product->assignProductGroup($value);
            $this->products->save($product);
            $this->audit->logFieldChanged('product', $productId, $field, $old, $value);
            $changed[] = $productId;
        }

        return $changed;
    }

    /**
     * Deletes the «Manual Sale» items of the products' variations and of the products themselves (two grouped reads, one delete per item through the repository). No audit, no restore (the owner's decision).
     *
     * @param  list<string>  $productIds
     * @return list<string> the products that had at least one manual price
     */
    private function resetManualPrices(array $productIds, MassEditResult $result): array
    {
        $manual = $this->lists->findSystemListByName('Manual Sale');

        if ($manual === null) {
            return [];
        }

        $listId = (string) $manual->id();
        $productOfVariation = DB::table('catalog_variations')->whereIn('product_id', $productIds)->pluck('product_id', 'id')->map(fn ($id): string => (string) $id)->all();
        $hit = [];

        foreach ($this->items->findByPriceListIdAndTargets($listId, PriceListItemTargetType::VARIATION, array_map('strval', array_keys($productOfVariation))) as $item) {
            $this->items->remove((string) $item->id());
            $result->removedPrices++;
            $hit[$productOfVariation[$item->targetId()] ?? ''] = true;
        }

        foreach ($this->items->findByPriceListIdAndTargets($listId, PriceListItemTargetType::PRODUCT, $productIds) as $item) {
            $this->items->remove((string) $item->id());
            $result->removedPrices++;
            $hit[$item->targetId()] = true;
        }

        unset($hit['']);

        return array_map('strval', array_keys($hit));
    }
}
