<?php

namespace App\Filament\Resources\ProductGroupResource\Pages;

use App\Filament\Resources\ProductGroupResource;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5. `code` is disabled
 * on the form (see ProductGroupResource::form()) so $data['code']
 * never differs from the loaded record's own code — only rename() is
 * ever called here.
 */
class EditProductGroup extends EditRecord
{
    protected static string $resource = ProductGroupResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $group = app(ProductGroupRepository::class)->findById((string) $record->id);

        if ($group === null) {
            throw new RuntimeException("ProductGroup \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($group->name() !== $data['name']) {
            $group->rename($data['name']);
        }

        app(ProductGroupRepository::class)->save($group);

        return ProductGroupModel::find($group->id());
    }
}
