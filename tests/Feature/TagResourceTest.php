<?php

namespace Tests\Feature;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Pages\CreateTag;
use App\Filament\Resources\TagResource\Pages\EditTag;
use App\Filament\Resources\TagResource\Pages\ListTags;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Tag;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagResourceTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_creating_a_tag_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateTag::class)
            ->fillForm(['name' => 'Summer', 'slug' => 'summer'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tag = app(TagRepository::class)->all()[0] ?? null;

        $this->assertNotNull($tag);
        $this->assertSame('Summer', $tag->name());
        $this->assertSame('summer', $tag->slug());
    }

    public function test_editing_a_tag_updates_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        app(TagRepository::class)->save($tag);

        Livewire::test(EditTag::class, ['record' => $tag->id()])
            ->fillForm(['name' => 'Summer Sale', 'slug' => 'summer-sale'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(TagRepository::class)->findById($tag->id());

        $this->assertSame('Summer Sale', $reloaded->name());
        $this->assertSame('summer-sale', $reloaded->slug());
    }

    public function test_a_duplicate_slug_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(TagRepository::class)->save(new Tag(id: null, name: 'Summer', slug: 'colliding-slug'));

        Livewire::test(CreateTag::class)
            ->fillForm(['name' => 'Not Summer', 'slug' => 'colliding-slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_duplicate_slug_is_rejected_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        app(TagRepository::class)->save(new Tag(id: null, name: 'Summer', slug: 'summer'));
        $winter = new Tag(id: null, name: 'Winter', slug: 'winter');
        app(TagRepository::class)->save($winter);

        Livewire::test(EditTag::class, ['record' => $winter->id()])
            ->fillForm(['slug' => 'summer'])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    public function test_editing_a_tag_with_its_own_unchanged_slug_does_not_false_positive(): void
    {
        $this->actingAsPanelAdministrator();

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        app(TagRepository::class)->save($tag);

        Livewire::test(EditTag::class, ['record' => $tag->id()])
            ->fillForm(['name' => 'Summer', 'slug' => 'summer'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(TagResource::getUrl('index'))->assertOk();
        $this->get(TagResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(TagResource::getUrl('index'))->assertOk();
        $this->get(TagResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(TagResource::getUrl('index'))->assertOk();
        $this->get(TagResource::getUrl('create'))->assertForbidden();
    }

    public function test_a_tags_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        app(TagRepository::class)->save($tag);
        $tagModel = TagModel::find($tag->id());

        $component = Livewire::test(ListTags::class);

        $component->assertTableActionVisible('edit', $tagModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($tagModel);
        $this->assertSame(TagResource::getUrl('view', ['record' => $tagModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(TagResource::getUrl('edit', ['record' => $tagModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListTags::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $tagModel);

        $this->get(TagResource::getUrl('view', ['record' => $tagModel]))->assertOk();
        $this->get(TagResource::getUrl('edit', ['record' => $tagModel]))->assertForbidden();
    }
}
