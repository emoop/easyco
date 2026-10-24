<?php

namespace App\Filament\Resources\BrandResource\Pages;

use App\Filament\Resources\BrandResource;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5, same fail-loud
 * "reload the real domain object or throw" pattern every prior EditX
 * page uses.
 */
class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * Seeds the FileUpload's initial state with the currently-stored
     * logo's path (not just its media asset id) — FileUpload's own
     * state is always a disk path, so this is what lets it render the
     * existing logo AND lets handleRecordUpdate() below tell "unchanged"
     * apart from "a new file was uploaded" by simple string comparison.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $brand = app(BrandRepository::class)->findById((string) $record->id);

        if ($brand === null) {
            throw new RuntimeException("Brand \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($brand->name() !== $data['name']) {
            $brand->rename($data['name']);
        }

        if ($brand->slug() !== $data['slug']) {
            $brand->changeSlug($data['slug']);
        }

        // ML-2: the logo is an id from the picker; «Махни» sets it to null and only detaches (the asset stays in the library, now unused)
        $logo = filled($data['logo_media_asset_id'] ?? null) ? (string) $data['logo_media_asset_id'] : null;

        if ($logo === null && $brand->logoMediaAssetId() !== null) {
            $brand->removeLogo();
        } elseif ($logo !== null && $logo !== $brand->logoMediaAssetId()) {
            $brand->setLogo($logo);
        }

        app(BrandRepository::class)->save($brand);

        return BrandModel::find($brand->id());
    }
}
