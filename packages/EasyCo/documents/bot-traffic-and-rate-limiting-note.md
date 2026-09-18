# Bot Traffic & Rate Limiting on Dynamic Endpoints (local note, future work)

Not designed yet. Raised by the domain owner from direct, ongoing
experience with aggressive scanning bots causing real problems on his
production WooCommerce site (raf.bg).

## The gap this covers

The storefront's Varnish full-page caching (decided in
production-requirements.md, applied to the storefront in
storefront-frontend-design.md §4, with §7 listing which routes are
cacheable) already means most storefront traffic - including scanning
bots - never reaches PHP/MySQL at all for a cacheable page (product,
category, etc.), a real structural advantage over a typical WordPress
install, where every request, bot or human, bootstraps the full stack.

The real, still-open gap: Varnish caches by exact URL. Dynamic,
high-cardinality endpoints - search with arbitrary query strings,
filtered product listings with many parameter combinations, cart-
adjacent pages - generate near-unlimited unique URLs, each a cache
miss hitting the database directly. An aggressive bot enumerating
these combinations bypasses the caching layer's protection entirely,
which is exactly the pattern the domain owner has observed causing
real problems in production. (storefront-frontend-design.md §7's
current route list has no search or filtered-listing route yet - this
note is about what those endpoints will need once they are added, not
a gap in anything built today.)

## Direction, not a decision

Self-hosted mitigation preferred, consistent with the domain owner's
own stated preference (never adopted Cloudflare, avoids third-party
vendor dependency where a viable alternative exists) - likely
candidates: rate limiting at the nginx/Apache layer, or Laravel's own
throttle middleware scoped to the specific dynamic endpoints (search,
filters), rather than a CDN/WAF vendor dependency. Not designed in
detail here.

## Relation to existing notes

- server-stability-observations.md (item 2, "Bot scanning traffic")
  records the same underlying problem from an earlier real incident,
  but frames it as an infrastructure-layer concern only ("Nginx/
  Cloudflare-level, not something to solve inside the Laravel
  application itself"). This note narrows to the dynamic endpoints
  Varnish cannot protect, and leaves app-level throttling open as a
  candidate alongside the web-server layer. The two framings need
  reconciling when this is actually designed. The Cloudflare example
  there is also at odds with production-requirements.md's "no CDN, no
  Cloudflare" stance, which is the stance this note follows.
- The only rate limiting in the codebase today is `throttle:6,1` on
  `POST /api/account/login` (routes/api.php; account-domain-design.md)
  - brute-force protection for one endpoint, not bot-traffic
  mitigation.

## Validation plan, once designed

The domain owner wants this tested by having Claude simulate an
aggressive scanning bot against the real implementation once it
exists - a real adversarial test, not just a design review.
