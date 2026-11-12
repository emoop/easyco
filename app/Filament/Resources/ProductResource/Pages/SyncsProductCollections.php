<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\ActivityLogger;
use EasyCo\Catalog\Contracts\ProductCollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\ProductCollection;

/**
 * P6e-1: the product form's «Колекции» (many-to-many, written like the tags: ProductCollectionRepository, one audit entry per added / removed collection, the NAME in the entry as for tags). One place for the
 * create and both edit pages.
 *
 * WHILE THE SWITCH IS OFF the field is hidden, so it does not dehydrate into the form data (the documented Filament gap the settings pages already work around): the sync is SKIPPED then, and in any case when the
 * key is absent — a product saved while collections are off KEEPS the collections it has. Nothing is read for the form either (no query while OFF).
 */
trait SyncsProductCollections
{
    /** @param array<string, mixed> $data */
    protected function fillProductCollections(array $data, string $productId): array
    {
        if (! ProductResource::collectionsEnabled()) {
            return $data;
        }

        $data['collections'] = array_map(
            fn ($c) => (string) $c->collectionId(),
            app(ProductCollectionRepository::class)->findByProductId($productId)
        );

        return $data;
    }

    /** @param array<string, mixed> $data the submitted form data */
    protected function syncProductCollections(string $productId, array $data, bool $isNewProduct = false): void
    {
        if (! ProductResource::collectionsEnabled() || ! array_key_exists('collections', $data)) {
            return;
        }

        $repository = app(ProductCollectionRepository::class);
        $submitted = array_map('strval', (array) $data['collections']);
        $current = [];

        foreach ($isNewProduct ? [] : $repository->findByProductId($productId) as $pivot) {
            $current[(string) $pivot->collectionId()] = $pivot;
        }

        foreach ($submitted as $collectionId) {
            if (! isset($current[$collectionId])) {
                $repository->save(new ProductCollection(id: null, productId: $productId, collectionId: $collectionId));
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'collections', null, CollectionModel::find($collectionId)?->name);
            }
        }

        foreach ($current as $collectionId => $pivot) {
            // numeric-string keys come back as ints (the same pre-existing trap syncCategories() documents): cast back
            $collectionId = (string) $collectionId;

            if (! in_array($collectionId, $submitted, true)) {
                $repository->remove((string) $pivot->id());
                app(ActivityLogger::class)->logFieldChanged('product', $productId, 'collections', CollectionModel::find($collectionId)?->name, null);
            }
        }
    }
}
