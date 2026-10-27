<?php

namespace App\Filament\Design\Zones;

use App\Filament\Design\FieldForms;
use App\Http\Controllers\Storefront\DesignPreviewController;
use App\Storefront\CheckoutHeader\CheckoutHeaderCatalog;
use App\Storefront\CheckoutHeader\CheckoutHeaderDrafts;
use App\Storefront\CheckoutHeader\CheckoutHeaderValidator;
use App\Storefront\Design\ComponentRegistry;
use App\Storefront\Design\LogoImage;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * The «Количка и поръчка» zone of the Design screen (DS-2b): the SIMPLIFIED header of the cart, the checkout and the order-received page (logo, a «Към магазина» link, an optional phone). A FORM
 * zone with a draft: «Приложи» saves the draft and reloads the preview (the cart page with the draft), «Публикувай» validates, stores and compiles it into the one cached appearance read.
 * The fields come from the registry's descriptor (ComponentRegistry::for('checkout_header')), so the panel and the validator share one list.
 */
final class CheckoutHeaderZone implements DesignZone
{
    public function key(): string
    {
        return 'checkout';
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
        return 'filament.pages.design.partials.schema-checkout';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.checkout');
    }

    public function hasDraft(): bool
    {
        return true;
    }

    public function hasUnpublishedChanges(): bool
    {
        return CheckoutHeaderDrafts::hasUnpublishedChanges();
    }

    public function previewNote(): ?string
    {
        return null;
    }

    public function load(): array
    {
        return ['values' => CheckoutHeaderDrafts::startingValues()];
    }

    public function previewUrl(): string
    {
        return DesignPreviewController::previewUrl(CheckoutHeaderCatalog::ZONE);
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

    /** @return array<string, array<string, mixed>> the registry's fields, each with its label key */
    private function fields(): array
    {
        $fields = [];

        foreach (ComponentRegistry::for('checkout_header')['checkout_header']['fields'] as $name => $field) {
            $fields[$name] = $field + ['label' => 'design.checkout.fields.'.$name];
        }

        return $fields;
    }

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        $components = array_map(static fn (string $name, array $field): Component => FieldForms::make($name, $field), array_keys($this->fields()), array_values($this->fields()));
        $components[] = Text::make(__('design.checkout.phone_hint'))->size('sm');

        return [Section::make(__('design.checkout.title'))->schema($components)];
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        return $tree['values'] ?? [];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $values = [];

        foreach ($this->fields() as $name => $field) {
            $value = $raw[$name] ?? null;
            $values[$name] = match ($field['kind']) {
                'bool' => (bool) $value,
                'int' => FieldValues::wholeNumber($value),
                'color' => is_string($value) && $value !== '' ? $value : null,
                'media' => FieldValues::id($value),
                default => $value === null ? '' : $value,
            };
        }

        $tree['values'] = $values;

        return $tree;
    }

    public function validate(array $tree): array
    {
        [$clean, $errors] = CheckoutHeaderValidator::validate($tree['values'] ?? []);

        // LG-1: the logo picture must exist, be READY and a PNG / WebP (the validator is pure: the storefront runs it on every read, without a query)
        $problem = is_int($clean['logo_image'] ?? null) ? LogoImage::problem($clean['logo_image']) : null;
        $problem !== null && $errors['logo_image'] = $problem;

        return [$clean, $errors];
    }

    public function locate(array $tree, string $path): ?array
    {
        return array_key_exists($path, $this->fields()) ? ['kind' => 'form', 'id' => 'checkout', 'field' => $path] : null;
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'];
    }

    public function warnings(array $stored): array
    {
        return [];
    }

    public function save(array $stored): void
    {
        CheckoutHeaderDrafts::saveDraft($stored);
    }

    public function publish(array $stored): void
    {
        CheckoutHeaderDrafts::publish($stored);
    }

    public function discard(): void
    {
        CheckoutHeaderDrafts::discard();
    }
}
