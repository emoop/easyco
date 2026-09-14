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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['logo'] = BrandResource::logoPath($this->record);

        return $data;
    }

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

        $existingLogoPath = BrandResource::logoPath($record);
        $newLogoPath = $data['logo'] ?? null;

        if ($newLogoPath === null && $existingLogoPath !== null) {
            $brand->removeLogo();
        } elseif ($newLogoPath !== null && $newLogoPath !== $existingLogoPath) {
            // A genuinely new upload — never reuse or mutate the old
            // MediaAsset, mirroring the task's own explicit instruction;
            // the old row is simply left orphaned (media cleanup is a
            // separately tracked, deferred concern — see CLAUDE.md's
            // "Media cleanup" entry).
            $asset = BrandResource::createLogoMediaAsset($newLogoPath);
            $brand->setLogo($asset->id());
        }

        app(BrandRepository::class)->save($brand);

        return BrandModel::find($brand->id());
    }
}
