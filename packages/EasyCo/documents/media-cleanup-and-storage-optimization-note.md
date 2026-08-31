# Media Cleanup & Storage Optimization (local note, future work)

Two related but distinct ideas raised by the domain owner, both
explicitly deferred - not designed, not scheduled.

## 1. Admin UI for orphaned media cleanup

Detaching a MediaAsset from a Product/Variation (list/detach/reorder
work, media-domain-design.md) deliberately never deletes the
underlying MediaAsset row or its files - the asset may be reused
elsewhere. Over time this will accumulate orphaned MediaAsset rows
(attached to nothing) that still occupy storage. Needs an Admin UI
surface to find and bulk-delete these - not yet designed. Likely
needs a "find MediaAsset rows with zero ProductMedia/VariationMedia
references" query as its foundation, which doesn't exist yet.

## 2. SEO-driven variant pruning for archived products

Idea, not a decision: for a product that's archived/no longer sellable
(see product-archival-strategy-note.md), consider deleting all but a
medium-sized rendition of the PRIMARY photo only (sort_order = 0) -
freeing storage while keeping just enough imagery for the archived
page to still render reasonably and for SEO purposes. Explicitly not
decided: whether this happens automatically on archival, is a manual
admin action, or is even worth building at all vs. just leaving
storage as-is. No trigger point, no confirmation UX, nothing designed.
