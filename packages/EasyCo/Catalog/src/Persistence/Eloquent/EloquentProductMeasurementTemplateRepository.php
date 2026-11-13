<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use Illuminate\Support\Facades\DB;

/**
 * Product <-> measurement template on catalog_product_measurement_templates (product_id is the primary key, so one template per product is a real constraint). Plain query-builder access: the row
 * has no entity. Assigning never touches catalog_measurement_values.
 */
final class EloquentProductMeasurementTemplateRepository implements ProductMeasurementTemplateRepository
{
    public function templateIdFor(string $productId): ?string
    {
        $id = DB::table('catalog_product_measurement_templates')->where('product_id', $productId)->value('template_id');

        return $id !== null ? (string) $id : null;
    }

    public function assign(string $productId, string $templateId): void
    {
        if (! DB::table('catalog_products')->where('id', $productId)->exists()) {
            throw InvalidMeasurementValueException::unknownReference('product', $productId);
        }

        if (! DB::table('catalog_measurement_templates')->where('id', $templateId)->exists()) {
            throw InvalidMeasurementValueException::unknownReference('template', $templateId);
        }

        $now = now();

        DB::transaction(function () use ($productId, $templateId, $now): void {
            $updated = DB::table('catalog_product_measurement_templates')
                ->where('product_id', $productId)
                ->update(['template_id' => $templateId, 'updated_at' => $now]);

            if ($updated === 0) {
                DB::table('catalog_product_measurement_templates')->insert([
                    'product_id' => $productId,
                    'template_id' => $templateId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function clear(string $productId): void
    {
        DB::table('catalog_product_measurement_templates')->where('product_id', $productId)->delete();
    }
}
