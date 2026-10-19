<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Content\Menus\MenuException;
use App\Content\Menus\MenuFormState;
use App\Content\Menus\MenuModel;
use App\Content\Menus\MenuTree;
use App\Content\Menus\MenuWriter;
use App\Filament\Pages\Settings\ServerSideValidation;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\MenuResource;
use App\Services\ActivityLogger;
use App\Storefront\Menu\MenuCompileException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edit a menu: the form is the nested tree (MenuFormState converts it); saving is ONE call to MenuWriter::save(). A refusal comes back as the form's own field errors.
 * «Добави главните категории» appends the top-level categories that are not in this menu yet (each a dropdown that lists its sub-categories automatically).
 */
class EditMenu extends EditRecord
{
    use ServerSideValidation;

    protected static string $resource = MenuResource::class;

    /** UX-0: the browser's own `required` checks never block (or silently swallow) the Save: the server answers under the field. */
    public function getFormContentComponent(): Component
    {
        return static::withoutNativeValidation(parent::getFormContentComponent());
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = MenuFormState::toForm(MenuTree::forMenu((int) $data['id']));

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MenuModel $record */
        $state = (array) ($data['items'] ?? []);
        $this->resolveUploads($state);
        [$tree] = MenuFormState::fromForm($state);
        // The saved state is a plain list; the form's fields are addressed by the repeaters' own keys, so the map of paths comes from the live state (same order, same shape).
        [, $map, $types] = MenuFormState::fromForm((array) ($this->data['items'] ?? []));

        try {
            app(MenuWriter::class)->save($record, (string) ($data['name'] ?? ''), $tree);
        } catch (MenuException $exception) {
            $messages = [];

            foreach ($exception->errors as $path => $message) {
                $field = $path === 'name' ? 'name' : MenuFormState::fieldFor($path, $map, $types);
                $messages['data.'.$field] = $message;
            }

            $this->failFields($messages === [] ? ['data.items' => $exception->getMessage()] : $messages);
        } catch (MenuCompileException $exception) {
            $this->failFields(['data.items' => $exception->getMessage()]);
        }

        // Who saved which menu; never a label or a link.
        app(ActivityLogger::class)->logFieldChanged('storefront_menu', $record->key, 'items', null, 'saved');

        return $record->refresh();
    }

    /** A new picture file becomes a media asset (the same steps as every other upload); the item then holds the asset id. @param  array<string, mixed>  $state */
    private function resolveUploads(array &$state): void
    {
        foreach ($state as $topKey => $top) {
            foreach ((array) ($top['columns'] ?? []) as $columnKey => $column) {
                foreach ((array) ($column['column_items'] ?? []) as $itemKey => $item) {
                    $file = $item['image_file'] ?? null;

                    if (($item['type'] ?? null) === 'image' && is_string($file) && $file !== '') {
                        $state[$topKey]['columns'][$columnKey]['column_items'][$itemKey]['media_asset_id'] = BrandResource::createLogoMediaAsset($file)->id();
                    }
                }
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_main_categories')
                ->label(__('menus.actions.add_main_categories'))
                ->icon('heroicon-o-plus-circle')
                ->color('gray')
                ->action(function (): void {
                    $items = (array) ($this->data['items'] ?? []);
                    $present = [];

                    foreach ($items as $item) {
                        if (($item['type'] ?? null) === 'category' && ! empty($item['category_id'])) {
                            $present[(int) $item['category_id']] = true;
                        }
                    }

                    $added = 0;

                    foreach (DB::table('catalog_categories')->whereNull('parent_id')->orderBy('name')->orderBy('id')->pluck('id') as $id) {
                        if (! isset($present[(int) $id])) {
                            $items[(string) Str::uuid()] = ['type' => 'category', 'category_id' => (int) $id, 'submenu' => 'dropdown', 'with_children' => true, 'children_limit' => 12, 'new_tab' => false, 'visible' => true];
                            $added++;
                        }
                    }

                    $this->data['items'] = $items;
                    Notification::make()->title(__('menus.notice.categories_added', ['count' => $added]))->success()->send();
                }),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('menus.notice.saved');
    }
}
