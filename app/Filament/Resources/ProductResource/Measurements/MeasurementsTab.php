<?php

namespace App\Filament\Resources\ProductResource\Measurements;

use App\Filament\Design\InfoHint;
use App\Filament\Resources\MeasurementTemplateResource;
use App\Filament\Resources\ProductResource;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * MEAS-4: the product tab «Размери» (measurements-research.md «As built (MEAS-4)»). Builds the schema only; every read and the save are ProductMeasurementsPanel's, so the edit pages carry wiring lines and no
 * measurement logic. Shown on the two EDIT pages (a simple product: only «Общи размери» templates are offered), never in the create wizard (the size axes exist only after the creation).
 *
 * The picker is `live`: choosing another template changes the grid's columns at once in the form (nothing is saved until the product's «Запази»). Switching away from the product's current template shows a
 * visible warning (a Text, not a modal): the numbers the new template lacks are kept hidden.
 */
final class MeasurementsTab
{
    public const ID = 'measurements';

    public static function make(bool $simple): Tab
    {
        $panel = static fn (): ProductMeasurementsPanel => app(ProductMeasurementsPanel::class);
        $productId = static fn ($livewire): string => (string) $livewire->getRecord()->getKey();
        $canManage = static fn (): bool => ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE);

        return Tab::make(__('measurements.product_tab.title'))
            ->id(self::ID)
            ->visible(fn ($livewire): bool => $livewire instanceof EditRecord)
            ->schema([
                Hidden::make(ProductMeasurementsPanel::LOADED)->dehydrated(false),

                // no template exists (or a simple product has no «Общи размери» one): a hint with a link instead of an empty select
                Text::make(fn (): HtmlString => self::link(
                    __('measurements.product_tab.none_exist'),
                    MeasurementTemplateResource::getUrl('create'),
                    __('measurements.product_tab.create_template'),
                ))
                    ->color('warning')->size('sm')
                    ->visible(fn ($livewire): bool => ! $panel()->hasTemplates($livewire)),
                Text::make(fn (): HtmlString => self::link(
                    __('measurements.product_tab.none_single'),
                    MeasurementTemplateResource::getUrl('create'),
                    __('measurements.product_tab.create_single_template'),
                ))
                    ->color('warning')->size('sm')
                    ->visible(fn ($livewire): bool => $simple && $panel()->hasTemplates($livewire) && ! $panel()->hasActiveSingleTemplate($livewire)),

                Select::make(ProductMeasurementsPanel::TEMPLATE)
                    ->label(__('measurements.product_tab.template'))
                    ->afterLabel(InfoHint::make(__('measurements.product_tab.template_help'), 'measurement_template'))
                    ->options(fn ($livewire, Get $get): array => $panel()->pickerOptions($livewire, $productId($livewire), $simple, filled($get(ProductMeasurementsPanel::LOADED)) ? (string) $get(ProductMeasurementsPanel::LOADED) : null))
                    ->placeholder(__('measurements.product_tab.no_table'))
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($livewire, Get $get, Set $set) use ($panel, $productId): void {
                        // the numbers stored for the newly shown columns come into the form (typed text stays)
                        $panel()->clearCellErrors($livewire);
                        $set(ProductMeasurementsPanel::CELLS, $panel()->cellsAfterPick($livewire, $productId($livewire), (array) ($get(ProductMeasurementsPanel::CELLS) ?? [])));
                    })
                    ->disabled(fn (): bool => ! $canManage())
                    ->visible(fn ($livewire, Get $get): bool => $panel()->pickerOptions($livewire, $productId($livewire), $simple, filled($get(ProductMeasurementsPanel::LOADED)) ? (string) $get(ProductMeasurementsPanel::LOADED) : null) !== []),
                Text::make(fn (): HtmlString => self::link('', MeasurementTemplateResource::getUrl('index'), __('measurements.product_tab.open_templates')))
                    ->size('sm')
                    ->visible(fn ($livewire): bool => $panel()->hasTemplates($livewire)),

                // a visible warning (not a modal, not only an InfoHint) when the merchant leaves the product's current template
                Text::make(__('measurements.product_tab.switch_warning'))
                    ->color('warning')->size('sm')
                    ->visible(fn (Get $get): bool => filled($get(ProductMeasurementsPanel::LOADED)) && (string) ($get(ProductMeasurementsPanel::TEMPLATE) ?? '') !== (string) $get(ProductMeasurementsPanel::LOADED)),

                Actions::make([
                    Action::make('copy_first_row')
                        ->label(__('measurements.product_tab.copy_first_row'))
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->size('sm')
                        ->action(function ($livewire, Get $get, Set $set) use ($panel, $productId, $simple): void {
                            $grid = $panel()->grid($livewire, $productId($livewire), $simple, (string) ($get(ProductMeasurementsPanel::TEMPLATE) ?? ''));

                            if ($grid === null || $grid['single'] || count($grid['rows']) < 2) {
                                return;
                            }

                            // the same rule as «Тегло за всички варианти» (MEAS-1): only EMPTY cells are filled, what is typed is never overwritten
                            $cells = (array) ($get(ProductMeasurementsPanel::CELLS) ?? []);
                            $first = $grid['rows'][0]['key'];
                            $filled = 0;

                            foreach (array_slice($grid['rows'], 1) as $row) {
                                foreach ($grid['measures'] as $measure) {
                                    $source = trim((string) ($cells[$first][$measure['id']] ?? ''));

                                    if ($source !== '' && trim((string) ($cells[$row['key']][$measure['id']] ?? '')) === '') {
                                        $cells[$row['key']][$measure['id']] = $source;
                                        $filled++;
                                    }
                                }
                            }

                            $set(ProductMeasurementsPanel::CELLS, $cells);
                            $panel()->clearCellErrors($livewire);

                            Notification::make()->title(trans_choice('measurements.product_tab.copied', $filled, ['count' => $filled]))->success()->send();
                        }),
                ])
                    ->key('measurement-actions')
                    ->visible(function ($livewire, Get $get) use ($panel, $productId, $simple, $canManage): bool {
                        $grid = $panel()->grid($livewire, $productId($livewire), $simple, (string) ($get(ProductMeasurementsPanel::TEMPLATE) ?? ''));

                        return $canManage() && $grid !== null && ! $grid['single'] && count($grid['rows']) > 1 && $grid['measures'] !== [];
                    }),

                MeasurementGrid::make(ProductMeasurementsPanel::CELLS)
                    ->gridUsing(fn ($livewire, Get $get): ?array => $panel()->grid($livewire, $productId($livewire), $simple, (string) ($get(ProductMeasurementsPanel::TEMPLATE) ?? '')))
                    ->disabled(fn (): bool => ! $canManage())
                    ->visible(fn (Get $get): bool => filled($get(ProductMeasurementsPanel::TEMPLATE))),
            ]);
    }

    /** A short text with a link: «<text> <a>label</a>» — both escaped. */
    private static function link(string $text, string $url, string $label): HtmlString
    {
        return new HtmlString(trim(e($text).' <a href="'.e($url).'" class="underline">'.e($label).'</a>'));
    }
}
