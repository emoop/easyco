<?php

namespace App\Filament\Resources\HtmlBlockResource\Pages;

use App\Filament\Pages\Settings\ServerSideValidation;
use App\Content\HtmlBlocks\HtmlBlockStyle;
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

    /** MENU-5: the pictures of the form become the stored list (new files become Media assets); a refusal is a field error. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['pictures'] = HtmlBlockResource::picturesFromForm(is_array($data['pictures'] ?? null) ? $data['pictures'] : [], array_keys(is_array($this->data['pictures'] ?? null) ? $this->data['pictures'] : [])) ?: null;
        // HB-1: the «Стил на блока» is validated by HtmlBlockStyle; a refusal is a field error
        $data['style'] = HtmlBlockResource::styleFromForm($data['style'] ?? null, (int) $this->record->getKey());

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['pictures'] = HtmlBlockResource::picturesToForm(is_array($data['pictures'] ?? null) ? $data['pictures'] : null);
        $data['style'] = HtmlBlockStyle::formState($data['style'] ?? null);

        return $data;
    }
}
