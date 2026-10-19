<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Content\Exceptions\PageInvalidException;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Resources\PageResource;
use App\Filament\Support\StaffId;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;

/** Edit goes through PageWriter::update(); Preview and Archive are the only other actions (there is no delete). */
class EditPage extends EditRecord
{
    use ServerSideValidation;

    protected static string $resource = PageResource::class;

    /** UX-0: the browser's own `required` checks never block (or silently swallow) the Save: the server answers under the field. */
    public function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(parent::getFormContentComponent());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(PageWriter::class)->update($record, $data, StaffId::current());
        } catch (PageInvalidException $exception) {
            PageResource::fieldErrors($exception, $this);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            PageResource::previewAction(),
            Action::make('archive_page')
                ->label(__('pages.actions.archive'))
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('pages.archive.heading'))
                ->modalDescription(__('pages.archive.description'))
                ->modalSubmitActionLabel(__('pages.archive.submit'))
                ->modalCancelActionLabel(__('orders.modal.close'))
                ->visible(fn (PageModel $record): bool => $record->isPublished() || $record->in_footer)
                ->action(function (PageModel $record): void {
                    app(PageWriter::class)->archive($record);
                    $this->refreshFormData(['status', 'in_footer']);

                    Notification::make()->title(__('pages.notice.archived'))->success()->send();
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('pages.notice.saved');
    }
}
