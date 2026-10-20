<?php

namespace App\Filament\Resources\HtmlBlockResource\Pages;

use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Resources\HtmlBlockResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;

class CreateHtmlBlock extends CreateRecord
{
    use ServerSideValidation;

    protected static string $resource = HtmlBlockResource::class;

    /** UX-0: the server answers under the field, never the browser tooltip of a `required` input. */
    public function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(parent::getFormContentComponent());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('html_blocks.notice.saved');
    }
}
