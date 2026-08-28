# Barcode bulk-entry UX (from raf_pos review, admin UI concern — not yet due)

Confirmed real workflow in the source POS plugin
(load_vars_for_barcodes() + a global keydown Enter handler in pos.js):
operator loads a product's variations by base_sku, gets one input field
per variation, scans a physical barcode scanner into the focused field
(scanners send Enter automatically after the digits) - the handler
clears the current field's tracking, focuses the NEXT variation's
input automatically. Fast, low-error bulk capture of manufacturer-
provided barcodes across many variations in one continuous scan
sequence.

This is DIFFERENT from the catalog.variation.barcode Hook (which is a
pure extension point with no default generator - see
extensibility-design-and-hooks.md). This UI pattern is for quickly
CAPTURING real barcodes that already exist on physical products, not
generating fake ones. Both are needed, for different scenarios. This
belongs to future Admin UI work (Livewire/Filament, per
performance-and-channel-strategy.md), not backend Catalog work - noted
here so the pattern isn't lost before Admin UI's turn in the queue.
