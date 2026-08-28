# Cart Domain — Abandoned Cart Recovery (local note, not yet designed)

When the future Cart domain is designed, build abandoned-cart
recovery in from the start, not as an afterthought:

- Abandoned cart detection should fire a Hook action
  (e.g. `cart.abandoned`) using the existing Extensibility mechanism
  (packages/EasyCo/Extensibility) - no new event system needed.
- Email follow-up and push notifications are both just LISTENERS on
  that hook, not separate domains - mirrors how reporting turned out
  to be a query layer over Operational Sales rather than its own
  domain.
- Not designed yet - this is a placeholder so the idea isn't lost
  before Cart's turn in the queue.

## Confirmed priority (from further discussion with the domain owner)

Bulk email campaigns are explicitly NOT a near-term priority for this
merchant's fashion store - inventory turns over weekly and most items
sell within 2-3 days of an Instagram post, so there's no real "cold
list" to nurture yet. Abandoned cart recovery IS the real near-term
interest.

## Discount code needs a cross-domain hook

The abandoned-cart email should offer a discount code, not just a
reminder. That means the eventual design needs a way to generate a
single-use, time-limited discount code tied to a specific abandoned
cart (not a generic promo code). This is a **Pricing-domain concern**
(discount generation), triggered by a Cart-domain event - Cart itself
should not own the discount logic. Flag this cross-domain dependency
explicitly for whenever Cart is actually designed.

## Two sending strategies, two email categories - design deliberately

- **Single, event-triggered transactional-style emails** (order
  confirmation, abandoned cart) - reasonable to send directly from
  EasyCo's own infrastructure via a transactional email API (Amazon
  SES, Mailgun, Brevo, etc.), since these are low-volume,
  event-triggered, single-recipient sends, not bulk blasts. Requires
  one-time DNS setup (SPF/DKIM) for deliverability - a developer does
  this once, not something the merchant manages day-to-day.
- **Bulk marketing campaigns** (newsletters, promotions to segmented
  lists) - if/when this becomes relevant, integrate with a dedicated
  ESP rather than building a competing in-house campaign UI.
  Releva.ai (Sofia-based, documented Standard/Headless Integration
  APIs) is NOT a simple/lightweight email tool - correcting an earlier
  mischaracterization here after further discussion with the domain
  owner. It's a full marketing automation/orchestration platform
  combining email + Meta ads + push notifications into cross-channel
  conditional sequences (e.g. "if email unopened after N days, show a
  retargeting ad, then a push notification"). That's the opposite of
  simple for the merchant's actual near-term need.
- Correspondingly: for the specific near-term need (a single
  abandoned-cart email with a discount code), a full orchestration
  platform like Releva is overkill - it would mean learning an
  automation builder and potentially paying for Meta-ads/push features
  that aren't needed for one simple triggered email. The
  direct-send-from-EasyCo path above is therefore NOT a "budget
  compromise" for this use case - it's genuinely the more appropriate,
  simpler solution for exactly this problem.
- Releva (or a similar platform) remains a reasonable FUTURE candidate
  if/when the merchant wants genuine multi-channel marketing
  automation at scale - e.g. a slower-moving product line that needs
  real nurturing, unlike the current fast-turnover fashion inventory -
  not something to build toward now.
- Both paths route through the same Hook mechanism (`cart.abandoned`
  fires; different listeners could send directly OR forward to an ESP)
  - one event, pluggable backend, same pattern already proven for
  `catalog.product.slug`.
