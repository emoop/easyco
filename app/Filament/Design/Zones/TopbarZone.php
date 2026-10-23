<?php

namespace App\Filament\Design\Zones;

use App\Settings\AppearanceSettings as Appearance;
use App\Storefront\Appearance\AppearanceWriter;
use App\Storefront\Design\FieldKinds;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The «Топбанер» zone of the Design screen (DS-2b): a FORM zone, no tree. The banner has no draft (it has always been saved live from «Облик»): «Запази» stores through AppearanceWriter, the
 * very code the «Облик» page saves with, and the preview is the live home page. The fields and their rules are the «Облик» page's: a plain text of at most 160 characters (required while
 * the banner is on), a link that is a path in the shop or an https address, a link label of at most 40, two `#rrggbb` colours, an optional start and end (the end after the start), and the
 * same non-blocking contrast warning.
 */
final class TopbarZone implements DesignZone
{
    /** @var list<string> the panel's values */
    private const KEYS = ['enabled', 'text', 'url', 'link_label', 'text_color', 'bg_color', 'starts_at', 'ends_at'];

    public function key(): string
    {
        return 'topbar';
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
        return 'filament.pages.design.partials.schema-topbar';
    }

    public function previewTitle(): string
    {
        return (string) __('design.preview.topbar');
    }

    public function hasDraft(): bool
    {
        return false;
    }

    public function load(): array
    {
        $now = AppearanceWriter::current();

        return ['values' => [
            'enabled' => (bool) $now['banner_enabled'], 'text' => (string) $now['banner_text'], 'url' => (string) $now['banner_url'], 'link_label' => (string) $now['banner_link_label'],
            'text_color' => $now['c_banner_text'], 'bg_color' => $now['c_banner_bg'], 'starts_at' => $now['banner_starts_at'], 'ends_at' => $now['banner_ends_at'],
        ]];
    }

    /** The live home page; a changing query makes the frame fetch it again after «Запази». */
    public function previewUrl(): string
    {
        return route('storefront.home', [], false).'?ds='.time();
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

    public function propsComponents(array $tree, string $layout, ?array $selected): array
    {
        return [Section::make(__('design.topbar.title'))->schema([
            Toggle::make('enabled')->label(__('appearance.fields.banner_enabled')),
            TextInput::make('text')->label(__('appearance.fields.banner_text'))->maxLength(Appearance::BANNER_TEXT_MAX)->hint(static fn ($state): string => __('appearance.counter', ['count' => mb_strlen((string) $state), 'max' => Appearance::BANNER_TEXT_MAX])),
            TextInput::make('url')->label(__('appearance.fields.banner_url'))->helperText(__('appearance.fields.banner_url_help'))->maxLength(500),
            TextInput::make('link_label')->label(__('appearance.fields.banner_link_label'))->helperText(__('appearance.fields.banner_link_label_help'))->maxLength(Appearance::BANNER_LABEL_MAX),
            ColorPicker::make('text_color')->label(__('appearance.colors.banner_text'))->hex(),
            ColorPicker::make('bg_color')->label(__('appearance.colors.banner_bg'))->hex(),
            $this->contrast(),
            DateTimePicker::make('starts_at')->label(__('appearance.fields.banner_starts_at'))->seconds(false)->format('Y-m-d H:i'),
            DateTimePicker::make('ends_at')->label(__('appearance.fields.banner_ends_at'))->seconds(false)->format('Y-m-d H:i'),
        ])->columns(1)];
    }

    /** The contrast warning under the pair: shown only when both colours are valid and the ratio is under 4.5. Never blocks a save. */
    private function contrast(): Text
    {
        return Text::make(static fn (Get $get): string => (string) __('appearance.contrast_warning', ['ratio' => number_format(Appearance::contrastRatio((string) $get('text_color'), (string) $get('bg_color')), 2)]))
            ->color('warning')
            ->size('sm')
            ->visible(static fn (Get $get): bool => Appearance::isHex($get('text_color')) && Appearance::isHex($get('bg_color'))
                && Appearance::contrastRatio((string) $get('text_color'), (string) $get('bg_color')) < Appearance::MIN_CONTRAST);
    }

    public function propsState(array $tree, string $layout, ?array $selected): array
    {
        return $tree['values'] ?? [];
    }

    public function applyProps(array $tree, string $layout, ?array $selected, array $raw): array
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $value = $raw[$key] ?? ($key === 'enabled' ? false : null);
            $values[$key] = $key === 'enabled' ? (bool) $value : (is_string($value) ? $value : ($value === null ? null : (string) $value));
        }

        $tree['values'] = $values;

        return $tree;
    }

    public function validate(array $tree): array
    {
        $v = $tree['values'] ?? [];
        $errors = [];
        $out = ['enabled' => (bool) ($v['enabled'] ?? false)];
        $fail = static function (string $path, string $code, array $params = []) use (&$errors): void {
            $errors[$path] ??= (string) __('design.topbar.errors.'.$code, $params);
        };

        $text = trim((string) ($v['text'] ?? ''));
        $out['text'] = $text;

        if (mb_strlen($text) > Appearance::BANNER_TEXT_MAX) {
            $fail('text', 'too_long', ['max' => Appearance::BANNER_TEXT_MAX]);
        } elseif ($text !== '' && ! FieldKinds::isPlain($text)) {
            $fail('text', 'not_plain');
        } elseif ($text === '' && $out['enabled']) {
            $fail('text', 'required');
        }

        $url = trim((string) ($v['url'] ?? ''));
        $out['url'] = $url;

        if (mb_strlen($url) > 500) {
            $fail('url', 'too_long', ['max' => 500]);
        } elseif ($url !== '' && ! Appearance::isSafeBannerUrl($url)) {
            $errors['url'] ??= (string) __('appearance.errors.url');
        }

        $label = trim((string) ($v['link_label'] ?? ''));
        $out['link_label'] = $label;

        if (mb_strlen($label) > Appearance::BANNER_LABEL_MAX) {
            $fail('link_label', 'too_long', ['max' => Appearance::BANNER_LABEL_MAX]);
        } elseif ($label !== '' && ! FieldKinds::isPlain($label)) {
            $fail('link_label', 'not_plain');
        }

        foreach (['text_color' => 'banner_text', 'bg_color' => 'banner_bg'] as $key => $name) {
            $color = $v[$key] ?? null;
            $color = is_string($color) && $color !== '' ? $color : (string) Appearance::COLORS[$name][2];

            if (! Appearance::isHex($color)) {
                $errors[$key] ??= (string) __('appearance.errors.color');
                $out[$key] = $color;

                continue;
            }

            $out[$key] = strtolower($color);
        }

        foreach (['starts_at', 'ends_at'] as $key) {
            $date = trim((string) ($v[$key] ?? ''));
            $out[$key] = $date === '' ? null : $date;

            if ($date !== '' && ! $this->isDate($date)) {
                $fail($key, 'bad_date');
            }
        }

        if ($out['starts_at'] !== null && $out['ends_at'] !== null && ! isset($errors['starts_at'], $errors['ends_at']) && strcmp($out['ends_at'], $out['starts_at']) <= 0) {
            $errors['ends_at'] = (string) __('appearance.errors.ends_before_starts');
        }

        return [$out, $errors];
    }

    private function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date);

        return $parsed !== false && $parsed->format('Y-m-d H:i') === $date;
    }

    public function locate(array $tree, string $path): ?array
    {
        return in_array($path, self::KEYS, true) ? ['kind' => 'form', 'id' => 'topbar', 'field' => $path] : null;
    }

    public function fieldPath(array $at): ?string
    {
        return $at['field'];
    }

    public function warnings(array $stored): array
    {
        $ratio = Appearance::contrastRatio((string) $stored['text_color'], (string) $stored['bg_color']);

        return $ratio < Appearance::MIN_CONTRAST ? [(string) __('appearance.contrast_warning', ['ratio' => number_format($ratio, 2)])] : [];
    }

    /** «Запази»: the SAME save the «Облик» page makes (the colours and fonts it does not show stay as they are stored). */
    public function save(array $stored): void
    {
        AppearanceWriter::save(array_replace(AppearanceWriter::current(), [
            'banner_enabled' => $stored['enabled'], 'banner_text' => $stored['text'], 'banner_url' => $stored['url'], 'banner_link_label' => $stored['link_label'],
            'banner_starts_at' => $stored['starts_at'], 'banner_ends_at' => $stored['ends_at'],
            'c_banner_text' => $stored['text_color'], 'c_banner_bg' => $stored['bg_color'],
        ]));
    }

    public function publish(array $stored): void
    {
        $this->save($stored);
    }

    public function discard(): void
    {
    }
}
