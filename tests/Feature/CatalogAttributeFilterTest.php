<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\FilterDisplay;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CT-2 (domain and repository): an attribute may be a shop filter (`is_filterable`, default no) only with a CLOSED list of values (SELECT, MULTISELECT),
 * and says how its values are shown (`filter_display`: list | links | swatches, default list). Both are stored, read back, and refused when invalid.
 */
class CatalogAttributeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_attribute_is_not_a_filter_and_shows_its_values_as_a_list(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);

        $this->assertFalse($definition->isFilterable());
        $this->assertSame(FilterDisplay::LIST, $definition->filterDisplay());
    }

    public function test_a_select_or_multiselect_attribute_can_be_a_filter_and_a_text_number_or_boolean_one_cannot(): void
    {
        foreach ([AttributeType::SELECT, AttributeType::MULTISELECT] as $type) {
            $definition = new AttributeDefinition(id: null, code: 'a', name: 'A', type: $type, filterable: true);
            $this->assertTrue($definition->isFilterable(), $type->value);
        }

        foreach ([AttributeType::TEXT, AttributeType::NUMBER, AttributeType::BOOLEAN] as $type) {
            $refused = false;

            try {
                new AttributeDefinition(id: null, code: 'x', name: 'X', type: $type, filterable: true);
            } catch (InvalidAttributeFilterException) {
                $refused = true;
            }

            $this->assertTrue($refused, $type->value.' must be refused as a filter');
        }
    }

    public function test_switching_the_filter_on_for_a_text_attribute_is_refused_and_nothing_changes(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'note', name: 'Note', type: AttributeType::TEXT);

        $this->expectException(InvalidAttributeFilterException::class);
        $this->expectExceptionMessage('Only an attribute with a closed list of values');

        try {
            $definition->setFilterable(true);
        } finally {
            $this->assertFalse($definition->isFilterable());
        }
    }

    public function test_switching_the_filter_off_is_always_allowed(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT, filterable: true);

        $definition->setFilterable(false);

        $this->assertFalse($definition->isFilterable());
    }

    public function test_a_display_is_one_of_the_three_values_and_any_other_is_refused(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);

        $definition->setFilterDisplay('links');
        $this->assertSame(FilterDisplay::LINKS, $definition->filterDisplay());

        foreach (['', 'dropdown', 'LIST', 'list ', '<script>'] as $bad) {
            try {
                $definition->setFilterDisplay($bad);
                $this->fail("\"{$bad}\" must be refused");
            } catch (InvalidAttributeFilterException) {
                $this->assertSame(FilterDisplay::LINKS, $definition->filterDisplay(), 'a refused value changes nothing');
            }
        }
    }

    public function test_swatches_is_allowed_for_any_attribute_because_its_values_may_get_pictures_later(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'note', name: 'Note', type: AttributeType::TEXT);

        $definition->setFilterDisplay('swatches');

        $this->assertSame(FilterDisplay::SWATCHES, $definition->filterDisplay());
    }

    public function test_the_repository_stores_and_reads_both_values_and_an_old_row_reads_as_no_filter(): void
    {
        $repository = app(AttributeDefinitionRepository::class);
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT, filterable: true, filterDisplay: FilterDisplay::SWATCHES);
        $repository->save($definition);

        $reloaded = $repository->findById($definition->id());
        $this->assertTrue($reloaded->isFilterable());
        $this->assertSame(FilterDisplay::SWATCHES, $reloaded->filterDisplay());

        $plain = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::SELECT);
        $repository->save($plain);
        $this->assertFalse($repository->findById($plain->id())->isFilterable());
        $this->assertSame(FilterDisplay::LIST, $repository->findById($plain->id())->filterDisplay());
    }

    public function test_the_enum_has_exactly_the_three_displays_the_owner_asked_for(): void
    {
        $this->assertSame(['list', 'links', 'swatches'], array_map(fn (FilterDisplay $d) => $d->value, FilterDisplay::cases()));
    }
}
