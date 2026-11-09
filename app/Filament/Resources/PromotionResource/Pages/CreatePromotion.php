<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Services\Exceptions\PromotionInvalidException;
use App\Services\PromotionWriter;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create goes through PromotionWriter::create(): the parsing, the domain's invariants, the scopes and the one transaction are the service's.
 *
 * «Дублирай» opens this page with `?duplicate={id}`: the form is filled from that promotion with the code left empty (and the switch on).
 */
class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $source = request()->query('duplicate');
        $model = is_scalar($source) ? PromotionModel::query()->find($source) : null;

        // no argument = the form's own defaults (discount type, the active switch); passing an empty array would skip them
        $model !== null ? $this->form->fill(PromotionResource::stateFor($model, duplicate: true)) : $this->form->fill();

        $this->callHook('afterFill');
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $promotion = app(PromotionWriter::class)->create($data);
        } catch (PromotionInvalidException $exception) {
            PromotionResource::fieldErrors($exception);
        }

        return PromotionModel::query()->findOrFail($promotion->id());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('promotions.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return PromotionResource::getUrl('index');
    }
}
