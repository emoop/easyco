<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Storefront\PageDesign\PageDesign;
use App\Storefront\PageDesign\PageDesignStore;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

/**
 * GL-2: the two plain sections of the «Дизайн» screen, «Страница и карти» and «Продуктова страница». Both are FORM zones with no draft («Запази» is live, like the top banner): no tree, ONE
 * properties form built from PageDesign's list of fields, so the panel, the validator and the compiler share it. «Върни по подразбиране» puts the defaults into the PANEL only (like the footer's
 * default layout): nothing is stored until «Запази».
 *
 * A subclass names its section and its groups: a group is a heading and the fields under it; a PAIR is the two columns «Десктоп» / «Телефон» side by side.
 */
abstract class PageDesignZone implements DesignZone
{
    /** One of PageDesign::SECTIONS (also the zone's key). */
    abstract protected function section(): string;

    /** @return list<array{heading: string, fields?: list<string>, pairs?: array<string, list<string>>}> the groups of the panel, in order */
    abstract protected function groups(): array;

    public function key(): string
    {
        return $this->section();
    }

    public function mode(): string
    {
        return self::FORM;
    }

    public function layouts(): array
    {
        return [];
    }

    public function hasDevices(): bool
    {
        return false;
    }

    public function schemaView(): string
    {
        return 'filament.pages.design.partials.schema-page-design';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.'.$this->section());
    }

    public function hasDraft(): bool
    {
        return false;
    }

    public function load(): array
    {
        return ['values' => PageDesignStore::values($this->section())];
    }

    public function previewScroll(): string
    {
        return '';
    }

    public function rows(array $tree, string $layout): array
    {
        return [];
    }

    public function rowName(array $row, string $layout): string
    {
        return '';
    }

    public function cellName(array $cell, string $layout): string
    {
        return '';
    }

    public function typeLabel(string $type): string
    {
        return $type;
    }

    public function summary(array $node): string
    {
        return '';
    }

    public function palette(array $tree, string $layout, ?string $cellId): array
    {
        return [];
    }

    public function newNode(string $type, string $layout, string $id): array
    {
        return [];
    }

    public function structureProblem(array $tree): ?string
    {
        return null;
    }

    // ---- the panel -------------------------------------------------------------------------------------------------------------------

    /** One control with its label, its option texts and its one line of hint (what it changes, the default, the unit). */
    private function control(string $name): Component
    {
        $field = PageDesign::fields($this->section())[$name] + ['label' => 'design.page_design.fields.'.$name, 'options_prefix' => 'design.page_design.options.'.$name.'.'];
        $control = FieldForms::make($name, $field);
        method_exists($control, 'helperText') && $control->helperText((string) __('design.page_design.hints.'.$name));

        return $control;
    }

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $sections = [];

        foreach ($this->groups() as $group) {
            $components = array_map(fn (string $name): Component => $this->control($name), $group['fields'] ?? []);

            foreach ($group['pairs'] ?? [] as $label => $names) {
                $components[] = Fieldset::make((string) __($label))->schema(array_map(fn (string $name): Component => $this->control($name), $names))->columns(1);
            }

            // the pairs sit side by side: «Десктоп» | «Телефон»
            $sections[] = Section::make((string) __($group['heading']))->schema(isset($group['pairs']) ? [Grid::make(['default' => 1, 'sm' => 2])->schema($components)] : $components)->columns(1);
        }

        return $sections;
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        return $tree['values'] ?? [];
    }

    /** The panel's values put back in the shape the validator takes: a number stays what was typed (the validator reads it), a choice is a string, a switch a boolean. */
    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $values = [];

        foreach (PageDesign::fields($this->section()) as $name => $field) {
            $value = $raw[$name] ?? null;
            $values[$name] = match ($field['kind']) {
                'bool' => (bool) $value,
                'enum' => is_string($value) || is_int($value) ? (string) $value : '',
                default => is_string($value) || is_int($value) || is_float($value) ? $value : null,
            };
        }

        $tree['values'] = $values;

        return $tree;
    }

    public function validate(array $tree): array
    {
        return PageDesign::validate($this->section(), $tree['values'] ?? []);
    }

    public function locate(array $tree, string $path): ?array
    {
        return array_key_exists($path, PageDesign::fields($this->section())) ? ['kind' => 'form', 'id' => $this->section(), 'field' => $path] : null;
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'];
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    // ---- storage ---------------------------------------------------------------------------------------------------------------------

    /** «Върни по подразбиране»: every field at its default, in the panel only. @return array<string, mixed> */
    public function defaultTree(): array
    {
        return ['values' => PageDesign::defaults($this->section())];
    }

    public function save(array $stored): void
    {
        PageDesignStore::save($this->section(), $stored);
    }

    public function publish(array $stored): void
    {
        $this->save($stored);
    }

    public function discard(): void
    {
    }
}
