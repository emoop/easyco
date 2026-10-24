<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Write-interception per admin-panel-design.md §5 — every write goes
 * through the real domain Brand + BrandRepository, never a raw
 * Eloquent ::create().
 */
class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $brand = new Brand(id: null, name: $data['name'], slug: $data['slug']);

        // ML-2: the picker hands over the id of a picture that is already in the library (checked by the field's MediaAssetId rule)
        if (filled($data['logo_media_asset_id'] ?? null)) {
            $brand->setLogo((string) $data['logo_media_asset_id']);
        }

        app(BrandRepository::class)->save($brand);

        return BrandModel::find($brand->id());
    }
}
