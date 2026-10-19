<?php

namespace Tests\Feature;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\NavigationGroup;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\CreatePage;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Filament\Resources\PageResource\Pages\ListPages;
use App\Filament\Resources\PageResource\RelationManagers\RevisionsRelationManager;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Pages, stage P1 (pages-design.md section 3): the admin «Страници» — who may open it (`content_manage`), the form through the writer,
 * the revisions list and the read-only revision view, the preview link, archive instead of delete.
 */
class PageResourceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function page(array $override = []): PageModel
    {
        return app(PageWriter::class)->create(array_merge(['title' => 'Returns', 'slug' => 'returns', 'body' => '<p>Text.</p>', 'status' => 'published'], $override));
    }

    // ---- who may open it ------------------------------------------------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(PageResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_administrator_and_manager_may_open_every_page(): void
    {
        $page = $this->page();

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(PageResource::getUrl('index'))->assertOk();
            $this->get(PageResource::getUrl('create'))->assertOk();
            $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk();
            $this->get(PageResource::getUrl('revision', ['record' => $page, 'revision' => 1]))->assertOk();
        }
    }

    public function test_without_content_manage_every_page_is_a_403_and_the_item_is_not_in_the_navigation(): void
    {
        $page = $this->page();

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW])] as $actAs) {
            $actAs();

            $this->get(PageResource::getUrl('index'))->assertForbidden();
            $this->get(PageResource::getUrl('create'))->assertForbidden();
            $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertForbidden();
            $this->get(PageResource::getUrl('revision', ['record' => $page, 'revision' => 1]))->assertForbidden();

            $this->assertFalse(PageResource::canAccess());
            Livewire::test(ListPages::class)->assertForbidden();
            Livewire::test(CreatePage::class)->assertForbidden();
            Livewire::test(EditPage::class, ['record' => $page->id])->assertForbidden();
        }
    }

    public function test_a_role_with_only_content_manage_may_open_it_and_the_item_is_in_the_content_group(): void
    {
        $this->actingAsCustomRole([Permission::CONTENT_MANAGE]);

        $this->get(PageResource::getUrl('index'))->assertOk();
        $this->assertTrue(PageResource::canAccess());
        $this->assertSame(NavigationGroup::CONTENT, PageResource::getNavigationGroup());
        $this->assertCount(1, PageResource::getNavigationItems());
    }

    public function test_there_is_no_delete_for_anybody(): void
    {
        $page = $this->page();
        $this->actingAsStaff('Administrator');

        $this->assertFalse(PageResource::canDelete($page));
        Livewire::test(EditPage::class, ['record' => $page->id])->assertActionDoesNotExist('delete');
    }

    // ---- create and edit through the writer ---------------------------------------------------------------------------------

    public function test_create_suggests_the_slug_from_the_title_and_saves_through_the_writer_with_the_staff_id(): void
    {
        $user = $this->actingAsStaff('Administrator');

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Доставка и плащане'])
            ->assertSet('data.slug', 'доставка-и-плащане')
            ->fillForm(['body' => '<p>Hello</p><script>alert(1)</script>', 'status' => 'draft'])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = PageModel::query()->where('slug', 'доставка-и-плащане')->firstOrFail();
        $this->assertSame('<p>Hello</p>', $page->body, 'the writer sanitized it');
        $this->assertSame('draft', $page->status);
        $this->assertSame((string) $user->getKey(), (string) DB::table('page_revisions')->where('page_id', $page->id)->value('staff_id'));
    }

    public function test_a_wrong_or_taken_slug_is_a_field_error(): void
    {
        $this->page();
        $this->actingAsStaff('Administrator');

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Other', 'slug' => 'returns', 'body' => '<p>x</p>', 'status' => 'draft'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Other', 'slug' => 'Bad Slug!', 'body' => '<p>x</p>', 'status' => 'draft'])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertSame(1, PageModel::query()->count());
    }

    public function test_editing_the_text_writes_a_revision_with_the_staff_id_and_a_seo_edit_does_not(): void
    {
        $page = $this->page();
        $user = $this->actingAsStaff('Manager');

        Livewire::test(EditPage::class, ['record' => $page->id])
            ->fillForm(['meta_title' => 'Only SEO'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(1, DB::table('page_revisions')->where('page_id', $page->id)->count());

        Livewire::test(EditPage::class, ['record' => $page->id])
            ->fillForm(['body' => '<p>New text.</p>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, DB::table('page_revisions')->where('page_id', $page->id)->count());
        $this->assertSame((string) $user->getKey(), (string) DB::table('page_revisions')->where('page_id', $page->id)->where('revision', 2)->value('staff_id'));
    }

    public function test_the_slug_change_warning_shows_only_for_a_published_page_whose_slug_changed(): void
    {
        $published = $this->page();
        $draft = $this->page(['title' => 'Draft', 'slug' => 'draft-page', 'status' => 'draft']);
        $this->actingAsStaff('Administrator');

        $warning = __('pages.help.slug_changed');

        Livewire::test(EditPage::class, ['record' => $published->id])->assertDontSee($warning)->fillForm(['slug' => 'returns-2'])->assertSee($warning);
        Livewire::test(EditPage::class, ['record' => $draft->id])->fillForm(['slug' => 'draft-2'])->assertDontSee($warning);
    }

    public function test_the_editor_toolbar_is_limited_to_the_allow_list(): void
    {
        $page = $this->page();
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(EditPage::class, ['record' => $page->id])->html();

        $this->assertStringNotContainsString('attachFiles', $html);
        $this->assertStringNotContainsString('codeBlock', $html);
        $this->assertStringNotContainsString('strike', $html);
    }

    public function test_archive_takes_a_page_off_the_site_and_the_footer_and_keeps_it(): void
    {
        $page = $this->page(['in_footer' => true]);
        $this->actingAsStaff('Administrator');

        Livewire::test(EditPage::class, ['record' => $page->id])->callAction('archive_page');

        $fresh = $page->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertFalse((bool) $fresh->in_footer);
        $this->get('/info/returns')->assertNotFound();
    }

    // ---- revisions and preview ----------------------------------------------------------------------------------------------------

    public function test_the_revisions_list_shows_every_revision_with_no_row_actions(): void
    {
        $page = $this->page();
        app(PageWriter::class)->update($page, ['title' => 'Returns v2']);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(RevisionsRelationManager::class, ['ownerRecord' => $page, 'pageClass' => EditPage::class]);

        $component->assertSee('Returns v2')->assertSee('Returns');
        $this->assertSame(2, DB::table('page_revisions')->where('page_id', $page->id)->count());
    }

    public function test_the_revision_view_prints_the_saved_text_clean_even_from_a_hand_edited_row(): void
    {
        $page = $this->page(['body' => '<p>First text.</p>']);
        DB::table('page_revisions')->where('page_id', $page->id)->update(['body' => '<p>First text.</p><script>alert("x")</script><img src=x onerror=alert(1)>']);
        $this->actingAsStaff('Administrator');

        $html = $this->get(PageResource::getUrl('revision', ['record' => $page, 'revision' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('First text.', $html);
        $this->assertStringNotContainsString('alert("x")', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->get(PageResource::getUrl('revision', ['record' => $page, 'revision' => 9]))->assertNotFound();
    }

    public function test_the_preview_action_gives_a_temporary_signed_address_that_shows_a_draft(): void
    {
        $page = $this->page(['status' => 'draft', 'slug' => 'secret']);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(EditPage::class, ['record' => $page->id]);
        $component->assertActionVisible('preview_page');

        $url = \App\Http\Controllers\Storefront\PageController::previewUrl($page);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    // UX-0: a value the person typed is the value they get back: created, reopened, edited, reopened, and the public page follows.
    public function test_every_field_survives_a_save_and_a_reload(): void
    {
        $this->actingAsStaff('Administrator');
        $values = [
            'title' => 'Одит страница', 'slug' => 'odit-stranitsa', 'body' => '<p>Текст на одит страницата.</p>', 'status' => 'published',
            'meta_title' => 'Одит SEO заглавие', 'meta_description' => 'Одит SEO описание', 'noindex' => true,
        ];

        Livewire::test(CreatePage::class)->fillForm($values)->call('create')->assertHasNoFormErrors();
        $page = PageModel::query()->where('slug', 'odit-stranitsa')->firstOrFail();

        Livewire::test(EditPage::class, ['record' => $page->id])->assertFormSet($values);
        $html = $this->get('/info/odit-stranitsa')->assertOk()->getContent();
        $this->assertStringContainsString('Одит SEO заглавие', $html);
        $this->assertStringContainsString('noindex', $html);

        $changed = ['title' => 'Одит страница 2', 'body' => '<p>Нов текст.</p>', 'meta_title' => 'Одит SEO 2', 'meta_description' => 'Описание 2', 'noindex' => false];
        Livewire::test(EditPage::class, ['record' => $page->id])->fillForm($changed)->call('save')->assertHasNoFormErrors();

        Livewire::test(EditPage::class, ['record' => $page->id])->assertFormSet($changed + ['slug' => 'odit-stranitsa', 'status' => 'published']);
        $this->assertSame(2, $page->refresh()->revision);
        $html = $this->get('/info/odit-stranitsa')->assertOk()->getContent();
        $this->assertStringContainsString('Нов текст.', $html);
        $this->assertDoesNotMatchRegularExpression('/name="robots" content="noindex"/', $html);
    }
}
