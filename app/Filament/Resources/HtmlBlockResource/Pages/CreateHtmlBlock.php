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

    /** MENU-5: the pictures of the form become the stored list (new files become Media assets); a refusal is a field error. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['pictures'] = HtmlBlockResource::picturesFromForm(is_array($data['pictures'] ?? null) ? $data['pictures'] : [], array_keys(is_array($this->data['pictures'] ?? null) ? $this->data['pictures'] : [])) ?: null;
        // HB-1: the «Стил на блока» is validated by HtmlBlockStyle; a refusal is a field error
        $data['style'] = HtmlBlockResource::styleFromForm($data['style'] ?? null, null);

        return $data;
    }
}
