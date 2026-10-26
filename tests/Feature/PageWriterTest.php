<?php

namespace Tests\Feature;

use App\Content\Exceptions\PageInUseException;
use App\Content\Exceptions\PageInvalidException;
use App\Content\Models\PageModel;
use App\Content\Models\PageRevisionModel;
use App\Content\PageSlug;
use App\Content\PageWriter;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Pages, stage P1 (pages-design.md sections 2, 3.2, 6): the ONE writer. The body is sanitized BEFORE it is saved (the page and its
 * revisions hold clean HTML only), a revision is written only when the sanitized title or body changes, the slug rule is enforced in
 * the writer AND in the database, and a page in use is never deleted.
 */
class PageWriterTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    private function writer(): PageWriter
    {
        return app(PageWriter::class);
    }

    /** @param array<string, mixed> $override */
    private function make(array $override = []): PageModel
    {
        return $this->writer()->create(array_merge([
            'title' => 'Delivery', 'slug' => 'delivery', 'body' => '<p>We deliver.</p>', 'status' => 'published',
        ], $override), '7');
    }

    // ---- the body is sanitized BEFORE it is saved ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function hostileBodies(): array
    {
        return [
            'a script element' => ['<p>Hi</p><script>alert(1)</script>'],
            'an onerror handler on an image' => ['<p>Hi</p><img src="x" onerror="alert(1)">'],
            'an onclick handler' => ['<p onclick="alert(1)">Hi</p>'],
            'a javascript: link' => ['<p><a href="javascript:alert(1)">x</a></p>'],
            'a javascript: link hiding a tab' => ['<p><a href="java&#x09;script:alert(1)">x</a></p>'],
            'a data: link' => ['<p><a href="data:text/html;base64,PHNjcmlwdD4=">x</a></p>'],
            'a style attribute' => ['<p style="position:fixed;top:0">Hi</p>'],
            'an iframe' => ['<p>Hi</p><iframe src="https://evil.test"></iframe>'],
            'an svg with a script' => ['<svg onload="alert(1)"><script>alert(2)</script></svg><p>Hi</p>'],
            'a form' => ['<form action="https://evil.test"><input name="x"></form><p>Hi</p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileBodies')]
    public function test_the_stored_body_and_its_revision_hold_clean_html_only(string $hostile): void
    {
        $page = $this->make(['body' => $hostile.'<h2>Kept</h2>']);

        foreach ([$page->fresh()->body, DB::table('pages')->where('id', $page->id)->value('body'), DB::table('page_revisions')->where('page_id', $page->id)->value('body')] as $stored) {
            $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
            $this->assertStringNotContainsStringIgnoringCase('onerror', $stored);
            $this->assertStringNotContainsStringIgnoringCase('onclick', $stored);
            $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $stored);
            $this->assertStringNotContainsStringIgnoringCase('script:', $stored);
            $this->assertStringNotContainsString('data:', $stored);
            $this->assertStringNotContainsStringIgnoringCase('style=', $stored);
            $this->assertStringNotContainsStringIgnoringCase('<img', $stored);
            $this->assertStringNotContainsStringIgnoringCase('<iframe', $stored);
            $this->assertStringNotContainsStringIgnoringCase('<svg', $stored);
            $this->assertStringNotContainsStringIgnoringCase('<form', $stored);
            $this->assertStringContainsString('<h2>Kept</h2>', $stored, 'what the allow-list keeps is kept');
        }
    }

    public function test_every_tag_the_allow_list_keeps_survives_and_links_are_made_safe(): void
    {
        $html = '<h2>A</h2><h3>B</h3><h4>C</h4><p><strong>s</strong> <em>e</em> <b>b</b> <i>i</i><br></p><ul><li>one</li></ul><ol><li>two</li></ol>'
            .'<blockquote>q</blockquote><table><thead><tr><th>h</th></tr></thead><tbody><tr><td>d</td></tr></tbody></table>'
            .'<p><a href="https://shop.test/x" onclick="x()">link</a> <a href="/info/y">relative</a> <a href="mailto:a@b.test">mail</a></p>';

        $stored = $this->make(['body' => $html])->body;

        foreach (['<h2>A</h2>', '<h3>B</h3>', '<h4>C</h4>', '<strong>s</strong>', '<em>e</em>', '<ul><li>one</li></ul>', '<ol><li>two</li></ol>', '<blockquote>q</blockquote>', '<table>', '<th>h</th>', '<td>d</td>'] as $kept) {
            $this->assertStringContainsString($kept, $stored, $kept);
        }

        $this->assertStringContainsString('<a href="https://shop.test/x" rel="noopener nofollow">link</a>', $stored);
        $this->assertStringContainsString('<a href="/info/y" rel="noopener nofollow">relative</a>', $stored);
        $this->assertStringContainsString('<a href="mailto:a@b.test" rel="noopener nofollow">mail</a>', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
    }

    public function test_a_body_over_the_size_limit_is_a_validation_error_never_a_silent_empty_page(): void
    {
        try {
            $this->make(['body' => '<p>'.str_repeat('x', PageWriter::BODY_MAX_BYTES).'</p>']);
            $this->fail('expected a refusal');
        } catch (PageInvalidException $e) {
            $this->assertArrayHasKey('body', $e->errors);
        }

        $this->assertSame(0, PageModel::count());

        $page = $this->make(['slug' => 'ok', 'body' => '<p>'.str_repeat('x', PageWriter::BODY_MAX_BYTES - 7).'</p>']);
        $this->assertSame(PageWriter::BODY_MAX_BYTES, strlen('<p>'.str_repeat('x', PageWriter::BODY_MAX_BYTES - 7).'</p>'));
        $this->assertNotSame('', $page->body, 'exactly at the limit is kept');

        try {
            $this->writer()->update($page, ['body' => str_repeat('y', PageWriter::BODY_MAX_BYTES + 1)]);
            $this->fail('expected a refusal on update');
        } catch (PageInvalidException $e) {
            $this->assertArrayHasKey('body', $e->errors);
        }

        $this->assertSame(1, $page->fresh()->revision, 'the refused save changed nothing');
    }

    public function test_a_page_cannot_be_published_with_nothing_left_of_its_body(): void
    {
        foreach (['', '   ', '<script>alert(1)</script>', '<img src=x>'] as $nothing) {
            try {
                $this->make(['slug' => 'empty-'.md5($nothing), 'body' => $nothing, 'status' => 'published']);
                $this->fail('expected a refusal for '.json_encode($nothing));
            } catch (PageInvalidException $e) {
                $this->assertArrayHasKey('body', $e->errors);
            }
        }

        $this->assertSame('draft', $this->make(['slug' => 'blank', 'body' => '', 'status' => 'draft'])->status, 'an empty DRAFT is fine');
    }

    // ---- revisions ---------------------------------------------------------------------------------------------------

    public function test_a_title_or_a_body_change_writes_revision_n_plus_1_and_nothing_else_does(): void
    {
        $page = $this->make();
        $this->assertSame([1, 1], [$page->revision, PageRevisionModel::where('page_id', $page->id)->count()]);
        $this->assertSame(7, (int) PageRevisionModel::first()->staff_id, 'who saved it');

        $page = $this->writer()->update($page, ['title' => 'Delivery and returns'], '8');
        $this->assertSame(2, $page->revision);

        $page = $this->writer()->update($page, ['body' => '<p>We deliver in 2 days.</p>'], '8');
        $this->assertSame(3, $page->revision);

        // SEO, footer, status and noindex changes write no revision and keep the number.
        $page = $this->writer()->update($page, ['meta_title' => 'SEO', 'meta_description' => 'desc', 'noindex' => true, 'status' => 'draft']);
        $this->assertSame(3, $page->revision);
        $this->assertSame(3, PageRevisionModel::where('page_id', $page->id)->count());

        // A save that changes nothing at all writes none either.
        $page = $this->writer()->update($page, []);
        $this->assertSame(3, $page->revision);
        $this->assertSame([1, 2, 3], PageRevisionModel::where('page_id', $page->id)->orderBy('revision')->pluck('revision')->all());
        $this->assertSame(['Delivery', 'Delivery and returns', 'Delivery and returns'], PageRevisionModel::where('page_id', $page->id)->orderBy('revision')->pluck('title')->all());
    }

    public function test_the_revision_follows_the_SANITIZED_text_not_the_raw_one(): void
    {
        $page = $this->make(['body' => '<p>Same.</p>']);

        // Different raw input, the same clean text: nothing the customer could read changed, so no revision.
        $page = $this->writer()->update($page, ['body' => '<p>Same.</p><script>alert(1)</script>']);
        $this->assertSame(1, $page->revision);

        $page = $this->writer()->update($page, ['body' => '<p>Same.</p><p>More.</p>']);
        $this->assertSame(2, $page->revision);
        $this->assertSame('<p>Same.</p><p>More.</p>', PageRevisionModel::where('revision', 2)->value('body'));
        $this->assertSame('<p>Same.</p>', PageRevisionModel::where('revision', 1)->value('body'), 'an old revision keeps its text');
    }

    public function test_a_revision_is_never_updated_or_deleted_and_the_database_refuses_to_delete_its_page(): void
    {
        $page = $this->make();
        $revision = PageRevisionModel::first();

        try {
            $revision->update(['body' => '<p>rewritten</p>']);
            $this->fail('a revision must not be updated');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $revision->delete();
            $this->fail('a revision must not be deleted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('<p>We deliver.</p>', PageRevisionModel::first()->body);

        // Even a page nothing points at cannot be deleted: the restrict foreign key keeps the evidence. Archive it instead.
        $this->expectException(QueryException::class);
        $page->delete();
    }

    public function test_publishing_sets_published_at_once_and_archiving_keeps_the_text(): void
    {
        $page = $this->make(['status' => 'draft']);
        $this->assertNull($page->published_at);

        $page = $this->writer()->update($page, ['status' => 'published']);
        $first = $page->published_at;
        $this->assertNotNull($first);

        $page = $this->writer()->archive($page);
        $this->assertSame(['draft', 1], [$page->status, $page->revision]);

        $page = $this->writer()->update($page, ['status' => 'published']);
        $this->assertEquals($first, $page->published_at, 'the FIRST publication is kept');
        $this->assertSame('<p>We deliver.</p>', $page->body);
    }

    // ---- slugs ---------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function invalidSlugs(): array
    {
        return [
            'empty' => [''], 'upper case' => ['Delivery'], 'Cyrillic upper case' => ['Доставка'], 'a space' => ['a b'], 'an underscore' => ['a_b'],
            'a leading hyphen' => ['-a'], 'a trailing hyphen' => ['a-'], 'a double hyphen' => ['a--b'], 'a slash' => ['a/b'], 'dots' => ['a.b'],
            'a query' => ['a?b'], 'percent' => ['a%20b'], 'only hyphens' => ['---'], 'too long' => [str_repeat('a', 121)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSlugs')]
    public function test_an_invalid_slug_is_refused_and_nothing_is_written(string $slug): void
    {
        try {
            $this->make(['slug' => $slug]);
            $this->fail('expected a refusal');
        } catch (PageInvalidException $e) {
            $this->assertArrayHasKey('slug', $e->errors);
        }

        $this->assertSame([0, 0], [PageModel::count(), PageRevisionModel::count()]);
        $this->assertFalse(PageSlug::isValid($slug));
    }

    public function test_valid_slugs_including_other_scripts_are_accepted(): void
    {
        foreach (['delivery', 'obshti-usloviya', 'политика-за-поверителност', 'доставка', 'a1', '2026', 'и', str_repeat('a', 120), 'a-b-c'] as $n => $slug) {
            $page = $this->make(['slug' => $slug, 'title' => 'T'.$n]);

            $this->assertSame($slug, $page->fresh()->slug);
            $this->assertTrue(PageSlug::isValid($slug));
        }
    }

    public function test_a_slug_is_unique_even_by_case_and_an_update_may_keep_its_own(): void
    {
        $page = $this->make();

        try {
            $this->make(['slug' => 'delivery', 'title' => 'Other']);
            $this->fail('a duplicate slug must be refused');
        } catch (PageInvalidException $e) {
            $this->assertSame([__('pages.errors.slug_taken')], $e->errors['slug']);
        }

        $this->assertSame('delivery', $this->writer()->update($page, ['title' => 'Delivery 2'])->slug, 'a page keeps its own slug');

        $other = $this->make(['slug' => 'returns', 'title' => 'Returns']);
        $this->expectException(PageInvalidException::class);
        $this->writer()->update($other, ['slug' => 'delivery']);
    }

    public function test_the_database_holds_the_same_slug_status_and_revision_rules(): void
    {
        $row = ['title' => 'T', 'body' => '', 'status' => 'draft', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()];

        foreach (['Upper', 'Доставка', 'a--b', '-a', 'a-', 'a_b', 'a b', 'a/b'] as $bad) {
            $this->assertCheckRefuses(fn () => DB::table('pages')->insert($row + ['slug' => $bad]), 'pages_slug_check', $bad);
        }

        $this->assertCheckRefuses(fn () => DB::table('pages')->insert(['slug' => 'ok-1', 'status' => 'archived'] + $row), 'pages_status_check', 'status');
        $this->assertCheckRefuses(fn () => DB::table('pages')->insert(['slug' => 'ok-2', 'revision' => 0] + $row), 'pages_revision_check', 'revision');

        foreach (['ok', 'доставка', 'a-b-2', 'и'] as $good) {
            DB::table('pages')->insert($row + ['slug' => $good]);
        }

        $this->assertSame(4, DB::table('pages')->count());
    }

    private function assertCheckRefuses(callable $write, string $check, string $what): void
    {
        try {
            $write();
            $this->fail("{$check} should refuse {$what}");
        } catch (QueryException $e) {
            $this->assertSame(3819, $e->errorInfo[1], $what);
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }

    // ---- plain text fields -----------------------------------------------------------------------------------------------

    public function test_the_title_and_the_seo_fields_are_plain_text_with_limits(): void
    {
        foreach ([
            ['title' => ''], ['title' => str_repeat('я', 201)], ['title' => "Bad\nTitle"], ['meta_title' => str_repeat('a', 201)],
            ['meta_description' => str_repeat('a', 321)], ['meta_description' => "x\0y"], ['status' => 'archived'],
        ] as $bad) {
            try {
                $this->make(['slug' => 'p-'.md5(json_encode($bad))] + $bad);
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (PageInvalidException $e) {
                $this->assertNotEmpty($e->errors);
            }
        }

        $page = $this->make(['title' => '  Padded  ', 'meta_title' => '   ', 'meta_description' => '']);
        $this->assertSame(['Padded', null, null], [$page->title, $page->meta_title, $page->meta_description]);
    }

    // ---- a page in use is never deleted -----------------------------------------------------------------------------------

    public function test_the_selected_terms_or_privacy_page_cannot_be_deleted(): void
    {
        $terms = $this->make(['slug' => 'terms', 'title' => 'Terms']);
        $privacy = $this->make(['slug' => 'privacy', 'title' => 'Privacy']);
        app(SiteSettingsRepository::class)->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $terms->id);
        app(SiteSettingsRepository::class)->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, (string) $privacy->id);

        foreach ([[$terms, 'terms_page'], [$privacy, 'privacy_page']] as [$page, $reason]) {
            try {
                $page->delete();
                $this->fail('a page in use must not be deleted');
            } catch (PageInUseException $e) {
                $this->assertSame(__('pages.in_use.'.$reason), $e->getMessage());
            }

            $this->assertTrue(PageModel::whereKey($page->id)->exists());
        }
    }

    public function test_a_page_an_order_accepted_cannot_be_deleted(): void
    {
        $page = $this->make(['slug' => 'accepted']);
        $other = $this->make(['slug' => 'other', 'title' => 'Other']);
        // An order that recorded the version `p{page}r1`; a page whose id merely STARTS with the same digits is not that page.
        $this->setUpRegionOrders();
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        DB::table('orders')->update(['terms_accepted_at' => now(), 'terms_version' => 'p'.$page->id.'r1']);

        try {
            $page->delete();
            $this->fail('expected a refusal');
        } catch (PageInUseException $e) {
            $this->assertSame(__('pages.in_use.accepted_by_order'), $e->getMessage());
        }

        try {
            $other->delete();
        } catch (PageInUseException) {
            $this->fail('another page is not in use');
        } catch (QueryException) {
            $this->addToAssertionCount(1); // only the restrict foreign key of its revision stops it
        }
    }
}
