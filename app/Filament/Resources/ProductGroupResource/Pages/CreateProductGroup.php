<?php

namespace App\Filament\Resources\ProductGroupResource\Pages;

use App\Filament\Resources\ProductGroupResource;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductGroupModel;
use EasyCo\Catalog\ProductGroup;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateProductGroup extends CreateRecord
{
    protected static string $resource = ProductGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $group = new ProductGroup(id: null, code: $data['code'], name: $data['name']);

        app(ProductGroupRepository::class)->save($group);

        return ProductGroupModel::find($group->id());
    }
}
