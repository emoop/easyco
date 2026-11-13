<?php

namespace App\Filament\Resources\MeasureResource\Pages;

use App\Filament\Resources\MeasureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The measures. Every write is a table action calling MeasureWriter, visible only with the permission. */
class ListMeasures extends ListRecords
{
    protected static string $resource = MeasureResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('measurements.measure.title');
    }

    /** See RoleResource\Pages\ListRoles's identical override. */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('measurements.measure.create')),
        ];
    }
}
