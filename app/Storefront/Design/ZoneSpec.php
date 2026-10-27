<?php

namespace App\Storefront\Design;

use App\Storefront\Footer\FooterLayout;
use App\Storefront\Header\HeaderCatalog;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\ProductSummary\ProductSummaryCatalog;
use InvalidArgumentException;

/**
 * The SHAPE of a zone of the storefront (DS-1, design-screen-design.md section 1): how many rows and cells it may hold, whether their names are fixed, how many nodes a cell and the
 * whole zone may hold, which types may appear only once and which types the zone allows. Every number is READ from the constants the existing validators use (HeaderLayout, FooterLayout,
 * HeaderCatalog): nothing is copied, so a limit changed there changes here.
 *
 * Zones now: `header.desktop`, `header.mobile` (the drawer is an extra cell with its own limit and types), `footer.upper`, `footer.lower`, `summary.main` (SC-1: the product page's right column, one row of one cell).
 */
final class ZoneSpec
{
    public const ZONES = ['header.desktop', 'header.mobile', 'footer.upper', 'footer.lower', 'summary.main'];

    /**
     * @param  list<string>|null  $rowNames  fixed row names (null = free rows, minRows..maxRows)
     * @param  list<string>|null  $cellNames  fixed cell names of every row (null = free cells, minCells..maxCells)
     * @param  array<string, int>  $typeLimits  type => the most times it may appear in the zone
     * @param  list<string>|null  $allowedTypes  null = every type of the zone's component set
     * @param  array<string, array{max: int, types: list<string>}>  $extraCells  cells outside the rows (the phone drawer)
     * @param  list<string>  $groups  the common groups the zone's rows may carry
     */
    public function __construct(
        public readonly string $zone,
        public readonly ?array $rowNames,
        public readonly int $minRows,
        public readonly int $maxRows,
        public readonly ?array $cellNames,
        public readonly int $minCells,
        public readonly int $maxCells,
        public readonly int $maxNodesPerCell,
        public readonly ?int $maxNodes,
        public readonly array $typeLimits,
        public readonly ?array $allowedTypes,
        public readonly array $extraCells = [],
        public readonly array $groups = [],
    ) {
    }

    public static function for(string $zone): self
    {
        $onceEach = array_fill_keys(HeaderCatalog::ONCE_PER_LAYOUT, 1);

        return match ($zone) {
            'header.desktop' => new self($zone, array_keys(HeaderLayout::ROW_HEIGHTS['desktop']), count(HeaderLayout::ROW_HEIGHTS['desktop']), count(HeaderLayout::ROW_HEIGHTS['desktop']), HeaderLayout::SLOTS,
                count(HeaderLayout::SLOTS), count(HeaderLayout::SLOTS), HeaderLayout::MAX_PER_SLOT, null, $onceEach, null),
            'header.mobile' => new self($zone, array_keys(HeaderLayout::ROW_HEIGHTS['mobile']), count(HeaderLayout::ROW_HEIGHTS['mobile']), count(HeaderLayout::ROW_HEIGHTS['mobile']), HeaderLayout::SLOTS,
                count(HeaderLayout::SLOTS), count(HeaderLayout::SLOTS), HeaderLayout::MAX_PER_SLOT, null, $onceEach, null,
                ['drawer' => ['max' => HeaderLayout::MAX_DRAWER, 'types' => HeaderCatalog::DRAWER_TYPES]]),
            'footer.upper' => new self($zone, null, 1, FooterLayout::MAX_ROWS, null, 1, FooterLayout::MAX_COLUMNS, FooterLayout::MAX_BLOCKS_PER_COLUMN, FooterLayout::MAX_BLOCKS, [], null),
            'footer.lower' => new self($zone, ['line'], 1, 1, ['line'], 1, 1, 1, 1, ['text_line' => 1], ['text_line']),
            'summary.main' => new self($zone, ['main'], 1, 1, ['main'], 1, 1, ProductSummaryCatalog::MAX_COMPONENTS, ProductSummaryCatalog::MAX_COMPONENTS, array_map(static fn (array $d): int => $d['max'], ProductSummaryCatalog::all()), null),
            default => throw new InvalidArgumentException('Unknown zone: '.mb_substr($zone, 0, 40)),
        };
    }

    /** May a node of this type be in the zone at all (the allowed list; null = any type the component set offers)? */
    public function allows(string $type): bool
    {
        return $this->allowedTypes === null || in_array($type, $this->allowedTypes, true);
    }

    /** @return array<string, mixed> the numbers as plain data (for the screen and for the tests) */
    public function toArray(): array
    {
        return [
            'zone' => $this->zone, 'row_names' => $this->rowNames, 'min_rows' => $this->minRows, 'max_rows' => $this->maxRows, 'cell_names' => $this->cellNames, 'min_cells' => $this->minCells,
            'max_cells' => $this->maxCells, 'max_nodes_per_cell' => $this->maxNodesPerCell, 'max_nodes' => $this->maxNodes, 'type_limits' => $this->typeLimits, 'allowed_types' => $this->allowedTypes,
            'extra_cells' => $this->extraCells, 'groups' => $this->groups,
        ];
    }
}
