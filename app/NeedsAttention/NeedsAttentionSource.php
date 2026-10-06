<?php

namespace App\NeedsAttention;

/**
 * The extension point behind the "Needs attention" page (shipping-domain-design.md §7.2.20 §6):
 * exactly the members that section names, and nothing more. A source is a READER of facts that are
 * already stored (an owed refund, a transfer whose receipts do not add up) — it writes nothing, and
 * it stores nothing of its own, which is what lets R4b add its two cash-on-delivery sources without
 * touching the page that draws them.
 *
 * A SOURCE COSTS EXACTLY TWO READS PER RENDER, count() and page() — one query each, never one query
 * per row. count() is called once per render per source, page() once per render per source (the page
 * mounts its rows through these two and no other way).
 *
 * THE ROWS THEMSELVES ARE OLDEST FIRST, because in this whole section age is the only order the page
 * is allowed to express (NeedsAttentionItem's own docblock): a source must therefore order by the
 * day the fact began, then by a stable tie-breaker, so a row can never wander between two reads.
 *
 * SOURCES ARE REGISTERED BY TAGGING THEM IN THE CONTAINER under TAG (`$this->app->tag([A::class,
 * B::class], NeedsAttentionSource::TAG)` in AppServiceProvider): the tag's own array order is the
 * order of the sections on the page, and a source that is not tagged is simply not on the page.
 */
interface NeedsAttentionSource
{
    /** The tag every source is registered under — see this interface's own docblock. */
    public const TAG = 'needs_attention.sources';

    /**
     * Stable identity of the source: its section's key in the view, and its paginator's page name —
     * so it is never translated and never changed once shipped (it is part of a URL).
     */
    public function key(): string;

    /** The section's heading, translated. */
    public function label(): string;

    /** How many rows this source has in total, right now — one COUNT. */
    public function count(): int;

    /**
     * One page of rows, oldest first. $page is one-based, as pagination is everywhere else; a page
     * past the end is an empty list, never an error (a fact can stop being true between the count()
     * that produced the pager and the page() that fills it).
     *
     * @return list<NeedsAttentionItem>
     */
    public function page(int $page, int $perPage): array;
}
