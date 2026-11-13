<?php

namespace Tests\Feature;

use App\Filament\Resources\AttributeDefinitionResource\Pages\CreateAttributeDefinition;
use App\Filament\Resources\AttributeDefinitionResource\Pages\EditAttributeDefinition;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** MEAS-3 section C: the «Показател за размер» checkbox on the attribute form. */
class AttributeSizeCheckboxTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('administrator@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    private function definition(string $code, AttributeType $type = AttributeType::SELECT, bool $isSize = false): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: $type, isSize: $isSize);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    private function stored(string $code): AttributeDefinition
    {
        return collect(app(AttributeDefinitionRepository::class)->all())->first(fn (AttributeDefinition $d) => $d->code() === $code);
    }

    public function test_the_default_is_false_and_saving_without_touching_it_keeps_false(): void
    {
        $this->signIn();

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'material', 'name' => 'Material', 'type' => AttributeType::SELECT->value])
            ->call('create')->assertHasNoFormErrors();

        $this->assertFalse($this->stored('material')->isSize());
    }

    public function test_it_is_saved_on_create_and_on_edit_and_cleared_again_with_an_audit_entry_each_time(): void
    {
        $this->signIn();
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'size', 'name' => 'Size', 'type' => AttributeType::SELECT->value, 'is_size' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertTrue($this->stored('size')->isSize());

        $id = $this->stored('size')->id();
        Livewire::test(EditAttributeDefinition::class, ['record' => $id])->assertFormSet(['is_size' => true])
            ->fillForm(['is_size' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($this->stored('size')->isSize());

        Livewire::test(EditAttributeDefinition::class, ['record' => $id])->fillForm(['is_size' => true])->call('save');
        $this->assertTrue($this->stored('size')->isSize());

        $entries = ActivityLogModel::query()->where('entity_type', 'attribute_definition')->where('field', 'is_size')->orderBy('id')->get()->map(fn ($r) => [$r->old_value, $r->new_value])->all();
        $this->assertSame([['0', '1'], ['1', '0'], ['0', '1']], $entries);
    }

    public function test_the_checkbox_is_hidden_for_other_types_and_a_tampered_value_is_saved_as_false(): void
    {
        $this->signIn();
        $text = $this->definition('note', AttributeType::TEXT);

        Livewire::test(EditAttributeDefinition::class, ['record' => $text->id()])->assertFormFieldHidden('is_size');
        Livewire::test(CreateAttributeDefinition::class)->fillForm(['type' => AttributeType::SELECT->value])->assertFormFieldVisible('is_size')
            ->fillForm(['type' => AttributeType::NUMBER->value])->assertFormFieldHidden('is_size');

        // a tampered request that sets it on a TEXT attribute (the page decides, not the browser)
        Livewire::test(EditAttributeDefinition::class, ['record' => $text->id()])->set('data.is_size', true)->call('save');
        $this->assertFalse($this->stored('note')->isSize());

        Livewire::test(CreateAttributeDefinition::class)
            ->fillForm(['code' => 'qty', 'name' => 'Qty', 'type' => AttributeType::NUMBER->value])->set('data.is_size', true)
            ->call('create');
        $this->assertFalse($this->stored('qty')->isSize());
    }

    public function test_a_second_flagged_attribute_shows_the_soft_notice_and_is_still_allowed(): void
    {
        $this->signIn();
        $notice = __('attribute_definitions.fields.is_size_notice');
        $first = $this->definition('size', isSize: true);
        $second = $this->definition('length');

        // the first (and only) flagged one: no notice
        Livewire::test(EditAttributeDefinition::class, ['record' => $first->id()])->assertDontSee($notice);

        // a second one: the notice appears once the box is ticked, saving is NOT blocked
        Livewire::test(EditAttributeDefinition::class, ['record' => $second->id()])
            ->assertDontSee($notice)
            ->fillForm(['is_size' => true])->assertSee($notice)
            ->call('save')->assertHasNoFormErrors();

        $this->assertTrue($this->stored('length')->isSize());
        $this->assertTrue($this->stored('size')->isSize());
    }
}
