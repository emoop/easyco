<?php

namespace App\Filament\Resources\ProductResource\Measurements;

use App\Filament\Support\MeasurementRefusals;
use Closure;
use Filament\Forms\Components\Field;

/**
 * MEAS-4: the measurements grid as ONE form field. Its state is `[row key][measure id] => text` (see ProductMeasurementsPanel). Kind `single`: a plain vertical list (label, input, unit); kind `by_size`: a table with a
 * sticky first column inside its own scroll box. Part of the product form: validated with it (every non-empty cell must parse, an error is shown ON that cell) and saved with the product's «Запази».
 *
 * The resolver closure returns the grid view model of the SELECTED template (ProductMeasurementsPanel::grid()); the view reads it once per render.
 */
final class MeasurementGrid extends Field
{
    protected string $view = 'filament.forms.components.measurement-grid';

    protected ?Closure $gridResolver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->default([]);
    }

    public function gridUsing(Closure $resolver): static
    {
        $this->gridResolver = $resolver;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getGrid(): ?array
    {
        // not cached here: the panel caches every read per request, and a render after a save must see the new state
        return $this->gridResolver === null ? null : $this->evaluate($this->gridResolver);
    }

    /** The decimal mark the browser normalises to on blur (the same rule as MeasurementNumber::format). */
    public function getDecimalMark(): string
    {
        return str_starts_with(strtolower(app()->getLocale()), 'bg') ? ',' : '.';
    }

    /**
     * Every cell of the state must be a number the data layer accepts (a field error on THAT cell, translated), checked with the rest of the form before anything is saved.
     */
    public function dehydrateValidationRules(array &$rules): void
    {
        parent::dehydrateValidationRules($rules);

        $rules[$this->getStatePath().'.*.*'] = [
            function (string $attribute, mixed $value, Closure $fail): void {
                $error = ProductMeasurementsPanel::cellError($value);

                if ($error !== null) {
                    $fail($error);
                }
            },
        ];
    }
}
