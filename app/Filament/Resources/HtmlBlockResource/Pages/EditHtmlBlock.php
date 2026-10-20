<?php

namespace App\Filament\Resources\HtmlBlockResource\Pages;

use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Resources\HtmlBlockResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;

class EditHtmlBlock extends EditRecord
{
    use ServerSideValidation;

    protected static string $resource = HtmlBlockResource::class;

    public function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(parent::getFormContentComponent());
    }

    protected function getHeaderActions(): array
    {
        return [HtmlBlockResource::deleteAction()];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('html_blocks.notice.saved');
    }
}
