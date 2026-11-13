<?php

namespace App\Filament\Resources\MeasurementTemplateResource\Pages;

use App\Filament\Resources\MeasurementTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/** The size templates. Every write is a table action calling MeasurementTemplateWriter, visible only with the permission. */
class ListMeasurementTemplates extends ListRecords
{
    protected static string $resource = MeasurementTemplateResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('measurements.template.title');
    }

    /** See RoleResource\Pages\ListRoles's identical override. */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('measurements.template.create')),
        ];
    }
}
