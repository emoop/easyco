<?php

namespace App\Filament\Resources\HtmlBlockResource\Pages;

use App\Filament\Pages\Settings\ServerSideValidation;
use App\Content\HtmlBlocks\HtmlBlockCard;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Storefront\Conditions\ProductConditions;
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
        // HB-5: the kind is not editable; a card block saves its settings, never the text kind's fields
        if ($this->record->kind === 'card') {
            $data['card'] = HtmlBlockResource::cardFromForm($data['card'] ?? null, (int) $this->record->getKey());
            $data['pictures'] = null;
            $data['style'] = null;
            $data['body'] = '';
            $data['conditions'] = HtmlBlockResource::conditionsFromForm($data['conditions'] ?? null);

            return $data;
        }

        $data['card'] = null;
        $data['pictures'] = HtmlBlockResource::picturesFromForm(is_array($data['pictures'] ?? null) ? $data['pictures'] : [], array_keys(is_array($this->data['pictures'] ?? null) ? $this->data['pictures'] : [])) ?: null;
        // HB-1: the «Стил на блока» is validated by HtmlBlockStyle; a refusal is a field error
        $data['style'] = HtmlBlockResource::styleFromForm($data['style'] ?? null, (int) $this->record->getKey());
        // HB-2: the «Показвай, когато…» conditions are validated by ProductConditions (the ids are checked on the server); a refusal is a field error
        $data['conditions'] = HtmlBlockResource::conditionsFromForm($data['conditions'] ?? null);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['pictures'] = HtmlBlockResource::picturesToForm(is_array($data['pictures'] ?? null) ? $data['pictures'] : null);
        $data['style'] = HtmlBlockStyle::formState($data['style'] ?? null);
        // HB-5: the card's settings as the form's state (a text block shows none: the defaults only feed the hidden fields)
        $data['card'] = HtmlBlockCard::formState(($data['kind'] ?? 'text') === 'card' ? ($data['card'] ?? null) : null);
        // HB-2: the stored conditions as the form's state; an id that does not exist any more is dropped
        $data['conditions'] = ProductConditions::formState($data['conditions'] ?? null);

        return $data;
    }
}
