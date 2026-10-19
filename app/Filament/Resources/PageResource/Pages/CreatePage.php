<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Content\Exceptions\PageInvalidException;
use App\Content\PageWriter;
use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Resources\PageResource;
use App\Filament\Support\StaffId;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;

/** Create goes through PageWriter::create(): the sanitizing, the first revision and every refusal are the writer's. */
class CreatePage extends CreateRecord
{
    use ServerSideValidation;

    protected static string $resource = PageResource::class;

    /** UX-0: the browser's own `required` checks never block (or silently swallow) the Save: the server answers under the field. */
    public function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(parent::getFormContentComponent());
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PageWriter::class)->create($data, StaffId::current());
        } catch (PageInvalidException $exception) {
            PageResource::fieldErrors($exception, $this);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('pages.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => $this->record]);
    }
}
