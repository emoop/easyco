<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeDisplayType;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CT-3 (domain and repository): an attribute says how its values are shown on the product page and in the card (`display_type`: swatch | buttons | dropdown | specs,
 * default dropdown). Any attribute type may have any display; an unknown value is refused; a stored unknown value reads as the default, so the list never breaks.
 */
class CatalogAttributeDisplayTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_attribute_shows_its_values_as_a_dropdown(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);

        $this->assertSame(AttributeDisplayType::DROPDOWN, $definition->displayType());
    }

    public function test_each_of_the_four_displays_is_set_and_read_back_through_the_repository(): void
    {
        $repository = app(AttributeDefinitionRepository::class);

        foreach (AttributeDisplayType::cases() as $display) {
            $definition = new AttributeDefinition(id: null, code: 'attr-'.$display->value, name: 'A', type: AttributeType::SELECT);
            $definition->setDisplayType($display->value);
            $repository->save($definition);

            $this->assertSame($display, $repository->findById($definition->id())->displayType(), $display->value);
        }
    }

    public function test_any_attribute_type_may_have_any_display(): void
    {
        $text = new AttributeDefinition(id: null, code: 'note', name: 'Note', type: AttributeType::TEXT);

        $text->setDisplayType('swatch');

        $this->assertSame(AttributeDisplayType::SWATCH, $text->displayType());
    }

    public function test_an_unknown_display_is_refused_and_nothing_changes(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        $definition->setDisplayType('buttons');

        foreach (['', 'dropdown-x', 'SWATCH', 'buttons ', '<b>'] as $bad) {
            try {
                $definition->setDisplayType($bad);
                $this->fail("\"{$bad}\" must be refused");
            } catch (InvalidAttributeFilterException) {
                $this->assertSame(AttributeDisplayType::BUTTONS, $definition->displayType(), 'a refused value changes nothing');
            }
        }
    }

    public function test_an_unknown_value_stored_in_the_database_reads_as_the_dropdown_and_does_not_break_the_list(): void
    {
        $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'odd', 'name' => 'Odd', 'type' => 'select', 'display_type' => 'hologram', 'created_at' => now(), 'updated_at' => now()]);

        $definition = app(AttributeDefinitionRepository::class)->findById((string) $id);

        $this->assertSame(AttributeDisplayType::DROPDOWN, $definition->displayType());
        $this->assertCount(1, app(AttributeDefinitionRepository::class)->all());
    }

    public function test_the_enum_has_exactly_the_four_displays_of_the_spec(): void
    {
        $this->assertSame(['swatch', 'buttons', 'dropdown', 'specs'], array_map(fn (AttributeDisplayType $d) => $d->value, AttributeDisplayType::cases()));
    }

    public function test_the_ct2_filter_settings_are_untouched_by_the_display_type(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT, filterable: true);

        $definition->setDisplayType('specs');

        $this->assertTrue($definition->isFilterable());
        $this->assertSame('list', $definition->filterDisplay()->value);
    }
}
