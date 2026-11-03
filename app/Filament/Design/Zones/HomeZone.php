<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\Tree;
use App\Storefront\Design\TreeEditor;
use App\Storefront\Design\ZoneStore\HomeAdapter;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeLayout;
use App\Storefront\Home\HomeValidator;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * The «Начална» zone of the Design screen (HP-1): the home page as ONE ordered list of SECTIONS, each holding ONE component (heading with lines, free HTML block; later HP-2 … HP-6 add more types to
 * HomeCatalog and nothing here changes). The home page is always the full width of the content area. The list is the generic tree with one row of one cell (HomeAdapter); the screen hides the row and
 * the cell (HasOneCell). The zone-wide spacing between the sections is the LAYOUT's property (the «Общи настройки» button); a section has its own «Показвай» and «Отстояние отгоре». Every rule comes from
 * HomeCatalog and HomeValidator, which stay the single authority; the draft / publish life is HomeDrafts. The default layout (no sections) is also the «Начални настройки»: the shop then prints the home page it
 * always printed.
 */
final class HomeZone implements DesignZone, HasOneCell
{
    public function key(): string
    {
        return 'home';
    }

    public function mode(): string
    {
        return self::TREE;
    }

    public function layouts(): array
    {
        return ['main'];
    }

    public function hasDevices(): bool
    {
        return false;
    }

    public function onlyCell(): string
    {
        return HomeAdapter::CELL;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-home';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.home');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return HomeDrafts::hasUnpublishedChanges();
    }

    /** The preview is the real home page with the APPLIED working copy (a signed preview); it reaches the shop after «Публикувай». */
    public function previewNote(): ?string
    {
        return (string) __('design.preview.applied_note');
    }

    public function load(): array
    {
        return HomeAdapter::toTree(HomeDrafts::startingLayout());
    }

    /** The default layout as a tree: «Начални настройки» puts it into the working copy. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return HomeAdapter::toTree(HomeLayout::default());
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl(HomeCatalog::ZONE);
    }

    public function previewScroll(): string
    {
        return '';
    }

    public function rows(array $tree, string $layout): array
    {
        return $tree['main']['rows'] ?? [];
    }

    public function rowName(array $row, string $layout): string
    {
        return (string) __('design.zones.home');
    }

    public function cellName(array $cell, string $layout): string
    {
        return (string) __('design.zones.home');
    }

    public function typeLabel(string $type): string
    {
        $descriptor = ComponentRegistry::for('home')[$type] ?? null;

        return $descriptor === null ? $type : (string) __($descriptor['label']);
    }

    /** A short summary for the chip: «скрита» for a hidden section, else a heading's own text. @param  array<string, mixed>  $node */
    public function summary(array $node): string
    {
        $settings = $node['settings'] ?? [];

        if (($settings['show'] ?? true) === false) {
            return (string) __('design.home.hidden');
        }

        return $node['type'] === 'heading' && is_string($settings['text'] ?? null) ? mb_strimwidth($settings['text'], 0, 24, '…') : '';
    }

    // ---- the palette -----------------------------------------------------------------------------------------------------------------

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        $items = $tree['main']['rows'][0]['cells'][0]['items'] ?? [];
        $out = [];

        foreach (HomeCatalog::all() as $type => $descriptor) {
            $reason = $cellId === null ? (string) __('design.palette.no_cell') : (count($items) >= HomeCatalog::MAX_SECTIONS ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null);
            $out[] = ['type' => $type, 'label' => $this->typeLabel($type), 'reason' => $reason, 'hint' => null];
        }

        return $out;
    }

    /** Why a component cannot be added: only the number of sections limits it (the screen asks when a type is not in the palette). */
    public function unavailableReason(string $type): ?string
    {
        return isset(HomeCatalog::all()[$type]) ? (string) __('design.home.errors.too_many', ['max' => HomeCatalog::MAX_SECTIONS]) : null;
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        return Tree::node($id, $type, HomeCatalog::defaultsFor($type));
    }

    /** The first rule about the LIST as a whole that the tree breaks (too many sections), or null. */
    public function structureProblem(array $tree): ?string
    {
        [, $errors] = HomeValidator::validate(HomeAdapter::fromTree($tree));

        foreach ($errors as $path => $message) {
            if (preg_match('/^sections(\.\d+)?$/', $path) === 1) {
                return $message;
            }
        }

        return null;
    }

    // ---- the properties --------------------------------------------------------------------------------------------------------------

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $current = $tree['main'] ?? [];

        if ($selected === null || $current === []) {
            return [Text::make(__('design.props.nothing'))];
        }

        if ($selected['kind'] === 'layout') {
            $field = ['kind' => 'enum', 'options' => HomeCatalog::SPACINGS, 'default' => HomeCatalog::DEFAULT_SPACING, 'options_prefix' => 'design.home.options.gap.', 'label' => 'design.home.settings.gap'];

            return [Section::make((string) __('design.home.general'))->schema([
                Group::make([$this->hinted(FieldForms::make('spacing', $field), 'gap')])->statePath('layout'),
            ])];
        }

        if ($selected['kind'] === 'node') {
            $node = TreeEditor::node($current, $selected['id']);
            $fields = $node === null ? [] : $this->fieldsOf($node['type']);

            return [Section::make($node === null ? '' : $this->typeLabel($node['type']))->schema([
                Group::make(array_map(fn (string $name, array $field): Component => $this->hinted(FieldForms::make($name, $field), $name), array_keys($fields), array_values($fields)))->statePath('settings'),
            ])];
        }

        return [Text::make(__('design.home.pick'))];
    }

    /** HINT-1: the explanation is an info icon next to the label (the text is in a bubble linked by aria-describedby), not a paragraph under the field. */
    private function hinted(Component $control, string $name): Component
    {
        $key = 'design.home.hints.'.$name;
        $text = (string) __($key);

        $text !== $key && method_exists($control, 'afterLabel') && $control->afterLabel(\App\Filament\Design\InfoHint::make($text, $name));

        return $control;
    }

    /** @return array<string, array<string, mixed>> the fields of a section type: the common ones, then the component's own, each with its own label */
    private function fieldsOf(string $type): array
    {
        $fields = [];

        foreach (HomeCatalog::common() + (HomeCatalog::all()[$type]['schema'] ?? []) as $name => $field) {
            $fields[$name] = $field + ['label' => 'design.home.settings.'.$name];
        }

        return $fields;
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        if ($selected === null) {
            return [];
        }

        if ($selected['kind'] === 'layout') {
            return ['layout' => ['spacing' => (string) ($tree['main']['props']['spacing'] ?? HomeCatalog::DEFAULT_SPACING)]];
        }

        if ($selected['kind'] !== 'node') {
            return [];
        }

        $node = TreeEditor::node($tree['main'] ?? [], $selected['id']);

        return $node === null ? [] : ['settings' => $node['settings']];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $current = $tree['main'] ?? [];

        if ($selected === null || $current === []) {
            return $tree;
        }

        if ($selected['kind'] === 'layout') {
            $in = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];
            // a value outside the closed list stays as it is: the validator refuses it with its message
            $tree['main']['props']['spacing'] = $in['spacing'] ?? ($current['props']['spacing'] ?? HomeCatalog::DEFAULT_SPACING);

            return $tree;
        }

        if ($selected['kind'] !== 'node' || ($node = TreeEditor::node($current, $selected['id'])) === null) {
            return $tree;
        }

        $in = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
        $settings = FieldValues::settings($this->fieldsOf($node['type']), $in) + $node['settings'];
        $tree['main'] = TreeEditor::setSettings($current, $selected['id'], $settings);

        return $tree;
    }

    // ---- validation and storage ------------------------------------------------------------------------------------------------------

    public function validate(array $tree): array
    {
        return HomeValidator::validate(HomeAdapter::fromTree($tree));
    }

    public function locate(array $tree, string $path): ?array
    {
        return HomeAdapter::locate($tree, $path);
    }

    public function fieldPath(array $at): ?string
    {
        if ($at['field'] === null) {
            return null;
        }

        return match ($at['kind']) {
            'node' => 'settings.'.$at['field'],
            'layout' => 'layout.'.$at['field'],
            default => $at['field'],
        };
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        HomeDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        HomeDrafts::publish($stored);
    }

    public function discard(): void
    {
        HomeDrafts::discard();
    }
}
