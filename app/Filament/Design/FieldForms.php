<?php

namespace App\Filament\Design;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Menus\MenuModel;
use App\Filament\Forms\Components\MediaPicker;
use App\Filament\Pages\Design\Menus;
use App\Filament\Resources\HtmlBlockResource;
use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Header\HeaderCatalog;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Illuminate\Support\HtmlString;

/**
 * THE KIND -> CONTROL MAPPING of the admin (DS-2a): one Filament component for one field of a component's schema (FieldKinds). The old «Хедър» page and the «Дизайн» screen both build
 * their controls here, so the same setting is the same control on both. Labels and option texts come from the `header.*` language keys (the catalog the fields belong to).
 */
final class FieldForms
{
    /**
     * LG-1: a picture field. `mimes` limits the types (and, with it, only plain `image` assets), `empty` is the language key of the text of the empty box, `help` of the grey line under the field,
     * `checker` shows a filled picture on a light checker (a transparent logo stays visible).
     *
     * @param  array<string, mixed>  $field
     */
    private static function media(string $name, array $field, string $label): MediaPicker
    {
        $picker = MediaPicker::make($name)->label($label)->default(null);

        if (isset($field['mimes'])) {
            $picker->mimeTypes($field['mimes'])->plainImagesOnly();
        }

        isset($field['empty']) && $picker->emptyText((string) __($field['empty']));
        isset($field['help']) && $picker->helperText((string) __($field['help']));
        ! empty($field['checker']) && $picker->checkerBackground();

        return $picker;
    }

    /** @param  array<string, mixed>  $field  the schema field: `kind` and its own keys */
    public static function make(string $name, array $field): Component
    {
        // a field may name its own label (the `menu` element's `style` is «Вид», not «Стил»)
        $label = __($field['label'] ?? 'header.settings.'.$name);
        // DS-2a.1: an enum may OFFER fewer options than it still accepts (`offer`): a value stored earlier stays valid, it is just not listed any more
        $offer = $field['offer'] ?? ($field['options'] ?? []);
        // the language file of an enum's option texts: the header's by default (DS-2b: the footer's blocks name `footer.options.`)
        $prefix = $field['options_prefix'] ?? 'header.options.';

        return match ($field['kind']) {
            'int' => TextInput::make($name)->label($label)->numeric()->integer()->minValue((int) ($field['min'] ?? 0))->maxValue((int) ($field['max'] ?? 9999))->default($field['default'] ?? null),
            'bool' => Toggle::make($name)->label($label)->default((bool) ($field['default'] ?? false)),
            'enum' => Select::make($name)->label($label)->native(false)->options(array_combine($offer, array_map(static fn (string $o): string => __($prefix.$o), $offer)))->default($field['default'] ?? $field['options'][0]),
            // DS-2a.1: the menus that exist (as the Menus editor lists them) and, as the empty choice, "the menu of this place" (`place` = header | drawer, set by the caller)
            'menu' => Select::make($name)->label($label)->native(false)
                ->placeholder(__('design.menu.place_'.($field['place'] ?? 'header')))
                ->options(static fn (): array => MenuModel::query()->orderBy('id')->pluck('name', 'id')->all())
                ->default(null)
                ->helperText(new HtmlString('<a href="'.e(Menus::getUrl()).'" target="_blank" rel="noopener">'.e(__('design.menu.edit_menus')).' →</a>')),
            // DS-2b: the HTML blocks that exist and a link to where they are made; the footer's `payments` as checkboxes in the catalog's order
            'html_block' => Select::make($name)->label($label)->native(false)
                ->options(static fn (): array => HtmlBlockModel::query()->orderBy('title')->pluck('title', 'id')->all())
                ->default(null)
                ->helperText(new HtmlString('<a href="'.e(HtmlBlockResource::getUrl('index')).'" target="_blank" rel="noopener">'.e(__('design.menu.edit_blocks')).' →</a>')),
            // ML-2: a picture of a component (no zone uses the kind yet; DS-4 will): the media picker, the value is the id of an image in the library
            'media' => self::media($name, $field, $label),
            'payments' => CheckboxList::make($name)->label($label)->columns(2)->default([])
                ->options(array_combine(FooterCatalog::PAYMENTS, array_map(static fn (string $m): string => __('footer.payments.'.$m), FooterCatalog::PAYMENTS))),
            'html' => Textarea::make($name)->label($label)->rows(4)->helperText(__('header.html_help'))->default(''),
            'textarea' => Textarea::make($name)->label($label)->rows(4)->default('')->maxLength((int) ($field['max'] ?? 2000)),
            'color' => ColorPicker::make($name)->label($label)->default($field['default'] ?? null),
            'social' => Repeater::make($name)->label($label)->maxItems((int) ($field['max'] ?? 8))->default([])
                ->schema([
                    Select::make('network')->label(__('header.settings.network'))->native(false)->options(array_combine(HeaderCatalog::SOCIAL_NETWORKS, array_map(static fn (string $n): string => __('header.social.'.$n), HeaderCatalog::SOCIAL_NETWORKS)))->required(),
                    TextInput::make('url')->label(__('header.settings.url'))->placeholder('https://')->required(),
                ])->columns(2),
            default => TextInput::make($name)->label($label)->default($field['default'] ?? '')->maxLength((int) ($field['max'] ?? 500)),
        };
    }
}
