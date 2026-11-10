<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceListWriter;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create goes through PriceListWriter::create(): the checks, the domain entity, the scopes in one transaction, the audit, the refresh and the hooks are the service's.
 *
 * «Дублирай» opens this page with `?duplicate={id}`: the form is filled from that campaign, with a copy name and switched OFF.
 */
class CreatePriceList extends CreateRecord
{
    protected static string $resource = PriceListResource::class;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $source = request()->query('duplicate');
        $model = is_scalar($source) ? PriceListModel::query()->find($source) : null;

        // no argument = the form's own defaults (the active switch); passing an empty array would skip them
        $model !== null && PriceListResource::isCampaign($model) ? $this->form->fill(PriceListResource::stateFor($model, duplicate: true)) : $this->form->fill();

        $this->callHook('afterFill');
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $list = app(PriceListWriter::class)->create($this->writerData($data));
        } catch (PriceListInvalidException $exception) {
            PriceListResource::fieldErrors($exception);
        }

        return PriceListModel::query()->findOrFail($list->id());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function writerData(array $data): array
    {
        return $data + ['scopes' => PriceListResource::scopesFrom($data)];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('price_lists.notice.created');
    }

    protected function getRedirectUrl(): string
    {
        return PriceListResource::getUrl('index');
    }
}
