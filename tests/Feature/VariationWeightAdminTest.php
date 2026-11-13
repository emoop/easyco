<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use App\Services\DuplicateProduct;
use App\Services\VariationWeightWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * MEAS-1 section A: the variation weight (whole grams, empty = unknown) in the admin - per variation row, the «за всички»
 * convenience, the simple product's field, the permission, the validation and the activity log. The write goes through the
 * existing Variation::setDimensions() (the three dimensions are kept).
 */
class VariationWeightAdminTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function adminLogin(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    /** @return array{0: string, 1: string, 2: string} product id, Black and White variation ids */
    private function variableProduct(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $a = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $b = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        app(ProductRepository::class)->save($product);
        DB::table('catalog_variations')->where('product_id', $product->id())->update(['shipping_class' => 'standard']);

        return [(string) $product->id(), (string) $a->id(), (string) $b->id()];
    }

    private function weightOf(string $variationId): ?int
    {
        $value = VariationModel::find($variationId)->weight_grams;

        return $value === null ? null : (int) $value;
    }

    /** @return list<string> the row keys of the Repeater, in order */
    private function rowKeys($component): array
    {
        return array_keys($component->get('data.existing_variations'));
    }

    /** Opens a FRESH page (as a merchant reopening the product), sets the first row's weight and saves. */
    private function saveFirstRowWeight(string $productId, string $weight): void
    {
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $keys = $this->rowKeys($component);
        $component->set("data.existing_variations.{$keys[0]}.weight_grams", $weight)->call('save')->assertHasNoFormErrors();
    }

    public function test_a_row_weight_saves_logs_old_to_new_keeps_the_dimensions_and_clears_to_null(): void
    {
        $this->adminLogin();
        [$productId, $black, $white] = $this->variableProduct();
        DB::table('catalog_variations')->where('id', $black)->update(['length_mm' => 100, 'width_mm' => 200, 'height_mm' => 300]);
        DB::table('activity_log')->delete();

        $this->saveFirstRowWeight($productId, '350');

        $this->assertSame(350, $this->weightOf($black));
        $this->assertNull($this->weightOf($white), 'the other variation is untouched');
        $row = DB::table('catalog_variations')->where('id', $black)->first();
        $this->assertSame([100, 200, 300], [(int) $row->length_mm, (int) $row->width_mm, (int) $row->height_mm], 'the three dimensions are kept');

        $log = DB::table('activity_log')->where('field', "variation[{$black}].weight_grams")->get();
        $this->assertCount(1, $log);
        $this->assertNull($log[0]->old_value);
        $this->assertSame('350', $log[0]->new_value);

        // a second save with the same value writes and logs nothing more
        DB::table('activity_log')->delete();
        $this->saveFirstRowWeight($productId, '350');
        $this->assertSame(0, DB::table('activity_log')->where('field', 'like', '%weight_grams')->count());

        // a change is logged old -> new, then an empty box clears it back to NULL (never 0)
        $this->saveFirstRowWeight($productId, '400');
        $this->assertSame(400, $this->weightOf($black));
        $this->saveFirstRowWeight($productId, '');
        $this->assertNull($this->weightOf($black));
        $last = DB::table('activity_log')->where('field', "variation[{$black}].weight_grams")->orderByDesc('id')->first();
        $this->assertSame('400', $last->old_value);
        $this->assertNull($last->new_value);
    }

    public function test_the_mass_field_fills_only_the_variations_without_a_weight(): void
    {
        $this->adminLogin();
        [$productId, $black, $white] = $this->variableProduct();
        DB::table('catalog_variations')->where('id', $black)->update(['weight_grams' => 500]);
        DB::table('activity_log')->delete();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $component->set('data.edit_all', true)
            ->assertFormFieldVisible('bulk_weight_grams')
            ->set('data.bulk_weight_grams', '250')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(500, $this->weightOf($black), 'a variation that already has a weight keeps it');
        $this->assertSame(250, $this->weightOf($white), 'the one without a weight is filled');
        $this->assertSame(1, DB::table('activity_log')->where('field', 'like', '%weight_grams')->count(), 'logged per variation changed');
        $this->assertSame(1, DB::table('activity_log')->where('field', "variation[{$white}].weight_grams")->count());
    }

    #[DataProvider('badWeights')]
    public function test_a_bad_row_weight_is_refused_and_nothing_is_written(string $bad): void
    {
        $this->adminLogin();
        [$productId, $black] = $this->variableProduct();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $keys = $this->rowKeys($component);
        $component->set("data.existing_variations.{$keys[0]}.weight_grams", $bad)->call('save')->assertHasFormErrors(["existing_variations.{$keys[0]}.weight_grams"]);

        $this->assertNull($this->weightOf($black));
    }

    /** @return array<string, array{0: string}> */
    public static function badWeights(): array
    {
        return ['fraction' => ['350.5'], 'zero' => ['0'], 'negative' => ['-1'], 'text' => ['abc'], 'too big' => ['100001']];
    }

    public function test_the_writer_itself_refuses_a_tampered_value_and_accepts_the_edges(): void
    {
        foreach (['350.5', '0', '-1', 'abc', '100001', 3.5, true] as $bad) {
            try {
                VariationWeightWriter::normalize($bad);
                $this->fail('expected a refusal for '.var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, VariationWeightWriter::normalize('1'));
        $this->assertSame(100000, VariationWeightWriter::normalize('100000'));
        $this->assertSame(350, VariationWeightWriter::normalize(350));
        $this->assertNull(VariationWeightWriter::normalize(''));
        $this->assertNull(VariationWeightWriter::normalize(null));
    }

    public function test_without_product_manage_the_page_is_refused_and_nothing_changes(): void
    {
        $role = \EasyCo\Staff\Role::create('View Only', [\EasyCo\Staff\Enums\Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only.weight@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);
        [$productId, $black] = $this->variableProduct();

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        $this->get(ProductResource::getUrl('edit-variable', ['record' => ProductModel::find($productId)]))->assertForbidden();

        $this->assertNull($this->weightOf($black));
    }

    public function test_a_simple_product_field_saves_logs_and_clears(): void
    {
        $this->adminLogin();
        $product = Product::createSimple('Simple Tee', 'SKU-S', 'simple-tee');
        app(ProductRepository::class)->save($product);
        $variationId = (string) app(ProductRepository::class)->findByIdWithVariations((string) $product->id())->universalVariation()->id();
        DB::table('activity_log')->delete();

        $component = Livewire::test(EditProduct::class, ['record' => $product->id()])->assertFormFieldExists('weight_grams');
        $component->fillForm(['weight_grams' => '220'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(220, $this->weightOf($variationId));
        $this->assertSame('220', DB::table('activity_log')->where('field', 'weight_grams')->value('new_value'));

        $component->fillForm(['weight_grams' => '0'])->call('save')->assertHasFormErrors(['weight_grams']);
        $this->assertSame(220, $this->weightOf($variationId));

        $component->fillForm(['weight_grams' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->weightOf($variationId));
    }

    public function test_the_simple_create_form_writes_the_weight_on_the_universal_variation(): void
    {
        $this->adminLogin();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Weighed Tee', 'slug' => 'weighed-tee', 'base_sku' => 'SKU-W', 'weight_grams' => '180'])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = ProductModel::where('slug', 'weighed-tee')->firstOrFail();
        $this->assertSame(180, (int) VariationModel::where('product_id', $product->id)->value('weight_grams'));
    }

    public function test_a_simple_duplicate_copies_the_weight_and_a_variable_duplicate_has_no_variations(): void
    {
        $simple = Product::createSimple('Simple Tee', 'SKU-S', 'simple-tee');
        app(ProductRepository::class)->save($simple);
        DB::table('catalog_variations')->where('product_id', $simple->id())->update(['weight_grams' => 330, 'length_mm' => 50]);

        $copy = app(DuplicateProduct::class)->duplicate((string) $simple->id());
        $row = DB::table('catalog_variations')->where('product_id', $copy->id())->first();
        $this->assertSame(330, (int) $row->weight_grams);
        $this->assertNull($row->length_mm, 'only the weight is copied, not the dimensions');

        [$variableId] = $this->variableProduct();
        $variableCopy = app(DuplicateProduct::class)->duplicate($variableId);
        $this->assertSame(0, DB::table('catalog_variations')->where('product_id', $variableCopy->id())->count());
    }
}
