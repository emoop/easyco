<?php

namespace App\Content;

use App\Content\Exceptions\PageInvalidException;
use App\Content\Models\PageModel;
use App\Content\Models\PageRevisionModel;
use App\Rules\PlainText;
use App\Storefront\Support\DescriptionSanitizer;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * THE ONLY WRITER of a content page (pages-design.md): the admin resource and the seeder call it, nothing else saves a page.
 *
 *  - The BODY is untrusted editor output: it is passed through DescriptionSanitizer::clean() BEFORE it is saved, so `pages.body` and
 *    `page_revisions.body` hold clean HTML only (the storefront cleans again at render). A body over the sanitizer's size limit is a
 *    validation error, never a silent empty page; a page cannot be PUBLISHED with nothing left of its body.
 *  - A REVISION is written only when the sanitized title or body changes (and on creation: revision 1); a save that changes only the
 *    SEO or footer fields writes none and keeps the number. Revisions are never updated or deleted.
 *  - The slug rule is PageSlug's (the database holds the same one as a CHECK); unique.
 *  - Archiving is "draft and out of the footer"; a hard delete is not offered (the revisions are evidence).
 */
final class PageWriter
{
    /** The sanitizer's own limit (DescriptionSanitizer::MAX_LENGTH): a longer body would be dropped to nothing, so it is refused first. */
    public const BODY_MAX_BYTES = 200_000;

    /**
     * @param  array<string, mixed>  $data  title, slug, body, status, meta_title, meta_description, noindex, in_footer, footer_order
     *
     * @throws PageInvalidException
     */
    public function create(array $data, ?string $staffId = null): PageModel
    {
        return $this->save(null, $this->validated($data, null), $staffId);
    }

    /**
     * @param  array<string, mixed>  $data  the same keys; a key left out keeps the page's own value
     *
     * @throws PageInvalidException
     */
    public function update(PageModel $page, array $data, ?string $staffId = null): PageModel
    {
        $merged = array_merge([
            'title' => $page->title, 'slug' => $page->slug, 'body' => $page->body, 'status' => $page->status,
            'meta_title' => $page->meta_title, 'meta_description' => $page->meta_description, 'noindex' => $page->noindex,
            'in_footer' => $page->in_footer, 'footer_order' => $page->footer_order,
        ], $data);

        return $this->save($page, $this->validated($merged, $page), $staffId);
    }

    /** Archive: status draft and out of the footer. Not a revision (the text did not change). */
    public function archive(PageModel $page): PageModel
    {
        $page->forceFill(['status' => PageModel::DRAFT, 'in_footer' => false])->save();

        return $page;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the values to store, body already sanitized
     *
     * @throws PageInvalidException
     */
    private function validated(array $data, ?PageModel $current): array
    {
        $body = (string) ($data['body'] ?? '');
        $data['meta_title'] = $this->blankToNull($data['meta_title'] ?? null);
        $data['meta_description'] = $this->blankToNull($data['meta_description'] ?? null);
        $data['title'] = trim((string) ($data['title'] ?? ''));
        $data['slug'] = trim((string) ($data['slug'] ?? ''));

        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:200', new PlainText()],
            'slug' => ['required', 'string', 'max:'.PageSlug::MAX_LENGTH, $this->slugRule($current)],
            'status' => ['required', Rule::in([PageModel::DRAFT, PageModel::PUBLISHED])],
            'meta_title' => ['nullable', 'string', 'max:200', new PlainText()],
            'meta_description' => ['nullable', 'string', 'max:320', new PlainText()],
            'noindex' => ['boolean'],
            'in_footer' => ['boolean'],
            'footer_order' => ['integer', 'min:0', 'max:65535'],
        ], [], [
            'title' => __('pages.fields.title'), 'slug' => __('pages.fields.slug'), 'meta_title' => __('pages.fields.meta_title'),
            'meta_description' => __('pages.fields.meta_description'), 'footer_order' => __('pages.fields.footer_order'),
        ]);

        $errors = $validator->errors()->toArray();

        if (strlen($body) > self::BODY_MAX_BYTES) {
            $errors['body'] = [__('pages.errors.body_too_large', ['max' => number_format(self::BODY_MAX_BYTES, 0, '.', ' ')])];
        }

        $clean = $errors === [] ? DescriptionSanitizer::clean($body) : '';

        if ($errors === [] && ($data['status'] ?? null) === PageModel::PUBLISHED && $clean === '') {
            $errors['body'] = [__('pages.errors.body_required_to_publish')];
        }

        if ($errors !== []) {
            throw new PageInvalidException($errors);
        }

        return [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'body' => $clean,
            'status' => $data['status'],
            'meta_title' => $data['meta_title'],
            'meta_description' => $data['meta_description'],
            'noindex' => (bool) ($data['noindex'] ?? false),
            'in_footer' => (bool) ($data['in_footer'] ?? false),
            'footer_order' => (int) ($data['footer_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $values already validated */
    private function save(?PageModel $page, array $values, ?string $staffId): PageModel
    {
        try {
            return DB::transaction(function () use ($page, $values, $staffId): PageModel {
                $isNew = $page === null;
                $page ??= new PageModel();
                $changed = $isNew || $page->title !== $values['title'] || $page->body !== $values['body'];

                $page->fill($values);
                $page->revision = $isNew ? 1 : ($changed ? $page->revision + 1 : $page->revision);

                if ($values['status'] === PageModel::PUBLISHED && $page->published_at === null) {
                    $page->published_at = now();
                }

                $page->save();

                if ($changed) {
                    PageRevisionModel::create([
                        'page_id' => $page->id, 'revision' => $page->revision, 'title' => $values['title'], 'body' => $values['body'], 'staff_id' => $staffId === null ? null : (int) $staffId,
                    ]);
                }

                return $page;
            });
        } catch (QueryException $e) {
            // The unique index is the real guard (rule 3): SQLSTATE 23000 and the driver's duplicate-key code, never message matching.
            if (($e->errorInfo[0] ?? null) === '23000' && in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                throw PageInvalidException::field('slug', __('pages.errors.slug_taken'));
            }

            throw $e;
        }
    }

    private function slugRule(?PageModel $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            $slug = (string) $value;

            if (! PageSlug::isValid($slug)) {
                $fail(__('pages.errors.slug_invalid'));

                return;
            }

            $taken = PageModel::query()->where('slug', $slug)->when($current !== null, fn ($query) => $query->where('id', '!=', $current->id))->exists();

            if ($taken) {
                $fail(__('pages.errors.slug_taken'));
            }
        };
    }

    private function blankToNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
