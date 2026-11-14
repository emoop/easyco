<?php

namespace App\Filament\Resources\ProductResource\Measurements;

use App\Filament\Resources\ProductResource;
use App\Filament\Support\MeasurementRefusals;
use App\Services\ProductMeasurementsWriter;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\SizeAxisResolver;
use EasyCo\Catalog\Enums\MeasurementTemplateKind;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\MeasurementNumber;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\Services\MeasurementRows;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Support\Facades\DB;
use Throwable;
use WeakMap;

/**
 * MEAS-4: everything the product tab «Размери» reads and saves, in ONE small class so the 3000-line edit page carries wiring only (measurements-research.md «As built (MEAS-4)»).
 *
 * THE FORM STATE (part of the product form, one save):
 *  - `measurement_template_id`  the picker ('' / null = no table),
 *  - `measurement_template_loaded` the template the page was opened with (not dehydrated; decides the «numbers are kept» warning),
 *  - `measurement_cells`        [row key][measure id] => the text typed. Row key = a size value id, or MeasurementValueRepository::PRODUCT_ROW. It holds EVERY stored number of the product (also the hidden
 *    ones, so switching the template back shows them at once) plus what the merchant typed; the grid shows only rows x measures of the selected template, and the save sends only those cells.
 *
 * READS are lazy and cached per Livewire request (a WeakMap keyed by the page): templates (2 statements, none when no template exists), the assignment (1), the template's measures (1), the axis with its
 * value names (2, only for a `by_size` template on a variable product), the stored numbers (1). Nothing depends on the number of sizes or measures: the same count for 3 x 3 and 12 x 12.
 */
final class ProductMeasurementsPanel
{
    public const TEMPLATE = 'measurement_template_id';

    public const LOADED = 'measurement_template_loaded';

    public const CELLS = 'measurement_cells';

    /** @var WeakMap<object, array<string, mixed>>|null */
    private static ?WeakMap $cache = null;

    public function __construct(
        private readonly ProductMeasurementTemplateRepository $assignments,
        private readonly MeasurementTemplateRepository $templates,
        private readonly MeasurementValueRepository $values,
        private readonly SizeAxisResolver $axis,
        private readonly MeasurementRows $rows,
        private readonly ProductMeasurementsWriter $writer,
    ) {
    }

    // ---------------------------------------------------------------------------------------- load

    /**
     * The tab's part of the form state when the page opens. A product WITHOUT a template costs the assignment read only (no numbers are loaded: the template picker loads them when one is chosen).
     *
     * @return array{measurement_template_id: string, measurement_template_loaded: string, measurement_cells: array<string, array<string, string>>}
     */
    public function load(object $livewire, string $productId): array
    {
        $assigned = $this->assigned($livewire, $productId);

        return [
            self::TEMPLATE => (string) $assigned,
            self::LOADED => (string) $assigned,
            self::CELLS => $assigned === null ? [] : $this->formatted($this->stored($livewire, $productId)),
        ];
    }

    /**
     * The cells for the state after the picker changed: every stored number, under whatever is already in the form (typed text wins). Called by the picker's afterStateUpdated.
     *
     * @param  array<string|int, mixed>  $current
     * @return array<string, array<string, string>>
     */
    public function cellsAfterPick(object $livewire, string $productId, array $current): array
    {
        $merged = $this->formatted($this->stored($livewire, $productId));

        foreach ($current as $row => $cells) {
            foreach ((array) $cells as $measure => $text) {
                if (trim((string) $text) !== '') {
                    $merged[(string) $row][(string) $measure] = (string) $text;
                }
            }
        }

        return $merged;
    }

    /** The errors of the grid's cells are dropped when the server itself changes the cells (the picker, «Копирай първия ред»): they described numbers that are no longer there. */
    public function clearCellErrors(object $livewire): void
    {
        $keys = array_values(array_filter($livewire->getErrorBag()->keys(), fn (string $key): bool => str_starts_with($key, 'data.'.self::CELLS.'.')));

        $keys === [] || $livewire->resetErrorBag($keys);
    }

    // ---------------------------------------------------------------------------------------- the grid view model

    /**
     * What the grid shows for the SELECTED template. Null when no template is selected or it no longer exists.
     *
     * @return array{
     *     template_id: string, kind: string, single: bool,
     *     measures: list<array{id: string, name: string, unit: string, archived: bool}>,
     *     rows: list<array{key: string, label: string}>,
     *     axis_name: string|null, fallback: bool, axis_definition_id: string|null,
     *     hidden: int
     * }|null
     */
    public function grid(object $livewire, string $productId, bool $simple, ?string $templateId): ?array
    {
        if ($templateId === null || $templateId === '') {
            return null;
        }

        $template = $this->template($livewire, $templateId);

        if ($template === null) {
            return null;
        }

        $single = $template->kind() === MeasurementTemplateKind::SINGLE;
        $info = $this->measureInfo($livewire, $template->measureIds());
        $measures = [];

        foreach ($template->measureIds() as $id) {
            if (isset($info[$id])) {
                $measures[] = ['id' => $id] + $info[$id];
            }
        }

        $rows = [];
        $axisName = null;
        $fallback = false;
        $axisDefinitionId = null;

        if ($single) {
            $rows[] = ['key' => MeasurementValueRepository::PRODUCT_ROW, 'label' => ''];
        } elseif (! $simple) {
            $axis = $this->axisOf($livewire, $productId);

            if ($axis !== null) {
                $axisName = $axis['name'];
                $fallback = ! $axis['is_size'];
                $axisDefinitionId = $axis['definition_id'];

                foreach ($axis['values'] as $id => $label) {
                    $rows[] = ['key' => (string) $id, 'label' => $label];
                }
            }
        }

        return [
            'template_id' => $templateId,
            'kind' => $template->kind()->value,
            'single' => $single,
            'measures' => $measures,
            'rows' => $rows,
            'axis_name' => $axisName,
            'fallback' => $fallback,
            'axis_definition_id' => $axisDefinitionId,
            'hidden' => $this->hiddenCount($this->stored($livewire, $productId), $rows, $measures),
        ];
    }

    /**
     * The stored numbers outside the grid (a measure the template dropped, another kind's rows, a size that left the axis): a count only, from the data the grid already read.
     *
     * @param  array<string, array<string, int>>  $stored
     * @param  list<array{key: string, label: string}>  $rows
     * @param  list<array{id: string, name: string, unit: string, archived: bool}>  $measures
     */
    private function hiddenCount(array $stored, array $rows, array $measures): int
    {
        $rowKeys = array_column($rows, 'key');
        $measureIds = array_column($measures, 'id');
        $hidden = 0;

        foreach ($stored as $row => $cells) {
            foreach ($cells as $measure => $_) {
                if (! in_array((string) $row, $rowKeys, true) || ! in_array((string) $measure, $measureIds, true)) {
                    $hidden++;
                }
            }
        }

        return $hidden;
    }

    /**
     * The picker's options: the ACTIVE templates (ordered by sort, then name, grouped by kind; on a SIMPLE product only `single`) plus the product's current template even when archived (marked), as a
     * grouped option list. [] when no template exists at all.
     *
     * @return array<string, array<string, string>>
     */
    public function pickerOptions(object $livewire, string $productId, bool $simple, ?string $currentId): array
    {
        $templates = $this->allTemplates($livewire);

        usort($templates, fn (MeasurementTemplate $a, MeasurementTemplate $b) => [$a->sort(), mb_strtolower($a->name())] <=> [$b->sort(), mb_strtolower($b->name())]);

        $groups = [];

        foreach ($templates as $template) {
            $isCurrent = $currentId !== null && (string) $template->id() === $currentId;

            if (! $isCurrent && (! $template->isActive() || ($simple && $template->kind() !== MeasurementTemplateKind::SINGLE))) {
                continue;
            }

            $label = $template->name().(! $template->isActive() ? ' — '.__('measurements.template.archived_marker') : '');
            $groups[__('measurements.kinds.'.$template->kind()->value)][(string) $template->id()] = $label;
        }

        return $groups;
    }

    /** Does ANY template exist / any `single` template (the «Create a template» hints)? From the cached list: no extra statement. */
    public function hasTemplates(object $livewire): bool
    {
        return $this->allTemplates($livewire) !== [];
    }

    public function hasActiveSingleTemplate(object $livewire): bool
    {
        foreach ($this->allTemplates($livewire) as $template) {
            if ($template->isActive() && $template->kind() === MeasurementTemplateKind::SINGLE) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------------------------- validation

    /**
     * One cell's text -> hundredths, or a translated message. Used by the form rule (a field error on THAT cell).
     *
     * @return string|null the error text, null when the cell is fine (an empty text is fine)
     */
    public static function cellError(mixed $text): ?string
    {
        try {
            MeasurementNumber::parse(is_scalar($text) || $text === null ? $text : '#');
        } catch (InvalidMeasurementValueException $e) {
            return MeasurementRefusals::message($e);
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------- save

    /**
     * Called by both edit pages AFTER the product's own save succeeded (the form validation, cell rules included, ran before). Writes through ProductMeasurementsWriter only: the template when it changed, then
     * the grid of the CURRENT template when any cell changed. A refusal of the data layer is a red notice; what was saved before stays saved. On success the tab's form state is refreshed.
     *
     * @param  array<string, mixed>  $data  the submitted form data
     * @return array<string, mixed>|null the refreshed tab state to put back into the form, null when nothing changed or the tab was not part of the submit
     */
    public function saveFromPage(object $livewire, string $productId, bool $simple, array $data): ?array
    {
        if (! array_key_exists(self::TEMPLATE, $data) || ! ProductResource::staffHasPermission(Permission::PRODUCT_MANAGE)) {
            return null;
        }

        try {
            $this->save($productId, $simple, $data);
        } catch (Throwable $exception) {
            if (! MeasurementRefusals::isRefusal($exception)) {
                throw $exception;
            }

            MeasurementRefusals::notify($exception, 'measurements.product_tab.notice.refused');
        }

        unset(self::$cache[$livewire]);

        return $this->load($livewire, $productId);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidMeasurementValueException|\EasyCo\Catalog\Exceptions\MeasurementRefusedException
     */
    public function save(string $productId, bool $simple, array $data): void
    {
        $newId = filled($data[self::TEMPLATE] ?? null) ? (string) $data[self::TEMPLATE] : null;
        $oldId = $this->assignments->templateIdFor($productId);
        $template = null;

        if ($newId !== null) {
            $template = $this->templates->findById($newId) ?? throw InvalidMeasurementValueException::unknownReference('template', $newId);

            // a template that is archived, or a `by_size` one on a simple product, can only stay (it was already there); the picker never offers them
            if ($newId !== $oldId && (! $template->isActive() || ($simple && $template->kind() !== MeasurementTemplateKind::SINGLE))) {
                throw InvalidMeasurementValueException::unknownReference('template', $newId);
            }
        }

        if ($newId !== $oldId) {
            $newId === null ? $this->writer->clearTemplate($productId) : $this->writer->assignTemplate($productId, $newId);
        }

        if ($template === null) {
            return; // «Без таблица»: only the assignment is cleared, the numbers stay stored (hidden)
        }

        $rowKeys = $this->rows->forProduct($productId, $template->kind());
        $cells = is_array($data[self::CELLS] ?? null) ? $data[self::CELLS] : [];
        $desired = [];

        foreach ($rowKeys as $row) {
            foreach ($template->measureIds() as $measure) {
                $text = $cells[$row][$measure] ?? null;
                $hundredths = MeasurementNumber::parse(is_scalar($text) ? $text : null);

                if ($hundredths !== null) {
                    $desired[$row][$measure] = $hundredths;
                }
            }
        }

        // unchanged = the typed cells of the grid equal the stored cells of the grid: no write, no hook, no audit
        $stored = $this->values->forProduct($productId);
        $inGrid = [];

        foreach ($rowKeys as $row) {
            foreach ($template->measureIds() as $measure) {
                if (isset($stored[$row][$measure])) {
                    $inGrid[$row][$measure] = $stored[$row][$measure];
                }
            }
        }

        if ($this->normalise($desired) === $this->normalise($inGrid)) {
            return;
        }

        $this->writer->saveGrid($productId, $desired);
    }

    /**
     * @param  array<string|int, array<string|int, int>>  $grid
     * @return array<string, array<string, int>>
     */
    private function normalise(array $grid): array
    {
        $out = [];

        foreach ($grid as $row => $cells) {
            foreach ($cells as $measure => $value) {
                $out[(string) $row][(string) $measure] = (int) $value;
            }
        }

        ksort($out);
        array_walk($out, fn (array &$cells) => ksort($cells));

        return $out;
    }

    // ---------------------------------------------------------------------------------------- cached reads

    private function assigned(object $livewire, string $productId): ?string
    {
        return $this->remember($livewire, 'assigned', fn () => $this->assignments->templateIdFor($productId));
    }

    /** @return array<string, array<string, int>> */
    private function stored(object $livewire, string $productId): array
    {
        return $this->remember($livewire, 'stored', fn () => $this->values->forProduct($productId));
    }

    /** @return MeasurementTemplate[] */
    private function allTemplates(object $livewire): array
    {
        return $this->remember($livewire, 'templates', fn () => $this->templates->all());
    }

    private function template(object $livewire, string $id): ?MeasurementTemplate
    {
        foreach ($this->allTemplates($livewire) as $template) {
            if ((string) $template->id() === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param  string[]  $ids
     * @return array<string, array{name: string, unit: string, archived: bool}>
     */
    private function measureInfo(object $livewire, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // the merchant's whole measure list in ONE statement (it is short and does not grow with a product's grid), read once per request
        return $this->remember($livewire, 'measures', function (): array {
            $out = [];

            foreach (DB::table('catalog_measures')->get(['id', 'name', 'unit', 'is_active']) as $row) {
                $out[(string) $row->id] = ['name' => (string) $row->name, 'unit' => (string) $row->unit, 'archived' => ! (bool) $row->is_active];
            }

            return $out;
        });
    }

    /** @return array{definition_id: string, name: string, is_size: bool, values: array<string, string>}|null */
    private function axisOf(object $livewire, string $productId): ?array
    {
        return $this->remember($livewire, 'axis', fn () => $this->axis->axisWithValues($productId));
    }

    private function remember(object $livewire, string $key, callable $read): mixed
    {
        self::$cache ??= new WeakMap();

        $bag = self::$cache[$livewire] ?? [];

        if (! array_key_exists($key, $bag)) {
            $bag[$key] = $read();
            self::$cache[$livewire] = $bag;
        }

        return $bag[$key];
    }

    /**
     * Stored hundredths -> the admin text of each cell.
     *
     * @param  array<string, array<string, int>>  $stored
     * @return array<string, array<string, string>>
     */
    private function formatted(array $stored): array
    {
        $locale = app()->getLocale();
        $out = [];

        foreach ($stored as $row => $cells) {
            foreach ($cells as $measure => $hundredths) {
                $out[(string) $row][(string) $measure] = MeasurementNumber::format($hundredths, $locale);
            }
        }

        return $out;
    }
}
