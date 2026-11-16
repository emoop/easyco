# P8d — app-side protection

What lives in the app (everything that can be tested without the server). The Varnish VCL, Anubis, Traefik and CrowdSec are P8c.

## The one switch

`SECURITY_MODE=log|block` (`config('security.mode')`, default `log`, a typo reads as `log`). Every NEW blocking rule asks `App\Security\SecurityMode::decide($rule, $context)`: in `log` it writes one `warning` («would block») and answers `false`, in `block` it answers `true`. Not governed by it: the CT-3 filter limit, the search limit, the API limiters, Filament's own 5-per-minute login limit.

## The rules

| Rule | Where | `log` | `block` |
|---|---|---|---|
| Admin-login lockout | `App\Filament\Pages\Auth\AdminLogin` (the panel's login page), `App\Security\Lockout\AdminLoginLockout` | counts, logs every lock (`Admin login locked.`: email sha256, IP, step), logs «would block» on a refused submit, lets the submit through | refuses a locked (account, IP) pair with the SAME generic message as a wrong password |
| Account-wide alarm | `AdminLoginAlarm` | 20 failed submits for one account (any IP) in 1 h → one `warning` + one mail to the shop's reply-to (else the transactional sender), max one mail per account per hour | the same, never a lock |
| Checkout honeypot | `Honeypot::check` in `CheckoutController` | logs, the order is placed | 422 with the form's generic `email` error, nothing written |
| Registration honeypot | `Honeypot::check` in `AccountRegistrationController` | logs, the account is made | 422, nothing written |

Lockout keys: account (trimmed, lower-cased email) **+ IP**; 5 failed credential submits → 5 min, the next 5 → 30 min, the next 5 and later → 24 h; the counter decays 24 h after the last failure; a success resets it; a submit during a lock is not counted. State is in the cache only (no table). The activity log is not used: its rows need a staff actor and a login failure has none.

Honeypot: `ec_hp` (`config('security.honeypot.field')`), partial `storefront/partials/honeypot.blade.php`, class `sf-hpot` (off-screen, not `display:none`; `sf-hp` is already the home page's class). The storefront has no registration form yet; the API check is in place for the day there is one.

## Trap, throttles, report endpoint

- `GET /__ec/trap`: logged (`Trap URL hit.`: IP, user agent cut to 120, path), answered with the storefront 404, never cacheable, `Disallow: /__ec/` in robots.txt.
- Livewire updates: limiter `livewire`, 300 per minute per staff id (IP when not authenticated), attached by `ThrottleLivewireUpdates` in the `web` group (it acts on the update route only; Livewire registers that route itself). Uploads keep `throttle:60,1`.
- `POST /csp-report`: sessionless, no CSRF, 204 always (413 over 8 KB, 429 over 30 per minute per IP), `application/csp-report` and `application/reports+json`, one log line per (directive, blocked host) per minute with the page path without its query.

## The bot-challenge switch

Setting `security.bot_challenge` = `off` (default) | `filters` | `misses`; anything else reads as `off` (`App\Security\BotChallenge\BotChallenge`). Written by Settings → Security (Administrator only: `SETTINGS_MANAGE`, checked on open and on save) and by `php artisan security:bot-challenge`. `BotChallengeSwitch` pushes it to the edge: `NullBotChallengeSwitch` today, the Varnish one in P8c; `security:push-bot-challenge` pushes the stored value every minute. `security.` is in `EloquentSiteSettingsRepository::INVISIBLE`: saving it never purges the page cache.

## Headers and CSP

`SecurityHeaders` (storefront groups, including the order-received page, which keeps `Referrer-Policy: no-referrer`): `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()` and `Content-Security-Policy-Report-Only` from `App\Security\Csp\CspSources`. Identical for every visitor, so cache-safe; no `'unsafe-inline'`, no nonce. HSTS is Traefik's. The admin gets none of it.

**How an integration adds a CSP source** (from its service provider, never from merchant input):

```php
app(\App\Security\Csp\CspSources::class)->allow('script-src', 'https://js.example.com');
```

Only `script-src`, `style-src`, `img-src`, `font-src`, `connect-src`, `frame-src`, `media-src` and only an `https://host[:port]` origin are accepted; anything else throws at registration. The media/storage host is added to `img-src` automatically when it is not the site's own host. Watch the `CSP report-only violation.` log lines before the policy is ever enforced.
