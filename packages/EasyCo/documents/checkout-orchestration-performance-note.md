# Checkout Orchestration — Performance/Reliability Risk (local note, not yet designed)

Real architectural risk flagged by the domain owner: Checkout is the
point where every independent domain converges at once - Catalog
(stock/variations), Pricing (current price), Operational Sales (final
SaleLine), and eventually Media, Shipping (external courier API calls),
Tax (Наредба Н-18 calculations). If Checkout synchronously calls each
domain in sequence, any slow piece (a slow Shipping API, a slow tax
calculation) directly delays the customer at checkout - a real
site-breaking risk under real load, not a hypothetical one.

Principles to apply when Cart/Checkout is actually designed:

1. Queue/async anything that doesn't block the final total or order
   confirmation - e.g. confirmation emails must not delay the
   checkout response, mirroring the same queued-job reasoning already
   applied to Media's image processing pipeline
   (media-domain-design.md §3).
2. External API calls (shipping rate lookups, etc.) need explicit
   timeouts and graceful degradation - never let checkout hang waiting
   on a slow third-party courier API; have a fallback (e.g. show a
   standard rate, complete the order, reconcile the exact shipping cost
   afterward) rather than blocking.
3. Checkout should NOT be one domain calling into every other domain
   directly. It needs a thin, dedicated orchestration layer (separate
   from any single domain's own code) that coordinates - the same
   "application-service orchestration, separate concern" pattern
   already flagged for InstallmentPlan::recordPayment()'s settlement-
   line handling in operational-sales-domain-design.md.

Not designed yet - this is a placeholder so the concern isn't lost
before Cart/Checkout's turn in the queue. The domain-isolation
architecture already in place (framework-agnostic domains, cross-domain-
by-id, no direct package dependencies) prevents CODE entanglement, but
does not automatically prevent PERFORMANCE entanglement at the
orchestration point - that needs to be designed explicitly, not assumed
to follow for free from the existing isolation.
