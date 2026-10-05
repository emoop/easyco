<?php

namespace App\Http\Middleware;

use App\Settings\StoreTimezone;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the `site.timezone` setting on every request and applies it to the
 * admin panel's own date/time DISPLAY — the second real consumer of the
 * Site Settings mechanism (site-settings-design.md), after ApplyStoreLocale.
 *
 * THE MECHANISM IS FILAMENT'S OWN, NOT A HAND-ROLLED ONE — confirmed
 * directly against the installed Filament v5.8.1 source (never from
 * memory): `Filament\Support\Facades\FilamentTimezone` fronts
 * `Filament\Support\TimezoneManager` (a container singleton, registered in
 * `Filament\Support\SupportServiceProvider::packageRegistered()`), whose
 * `get()` falls back to `config('app.timezone')` when nothing is set. Its
 * only readers in the whole vendor tree are the date/time formatting paths:
 * `Tables\Columns\Concerns\CanFormatState::getTimezone()`,
 * `Infolists\Components\Concerns\CanFormatState::getTimezone()` and
 * `Forms\Components\DateTimePicker::getTimezone()` — and each of those calls
 * it ONLY when the component is a real date/time one, ONLY as the fallback
 * for a component that has not been given its own ->timezone(...), and ONLY
 * to feed Carbon's setTimezone() inside the display formatting closure
 * (`Carbon::parse($state)->setTimezone(...)->translatedFormat(...)`). No
 * model, cast, service or column write ever consults it. That is what makes
 * this middleware display-only by construction rather than by promise:
 * every DateTimeImmutable this application writes to a column, passes into a
 * service, or compares in a domain guard is still produced and stored in
 * UTC exactly as before.
 *
 * STORAGE STAYS UTC BY NOT TOUCHING PHP'S OWN DEFAULT TIME ZONE. This
 * middleware deliberately does NOT call date_default_timezone_set() — the
 * obvious-looking one-liner that would be a real bug here, because
 * `new DateTimeImmutable()` (the clock every service in this codebase takes
 * as an explicit parameter) would then be born in the merchant's local zone
 * and stored as if it were UTC. Nothing below the display layer changes.
 *
 * REGISTERED IN BOTH PIPELINES, LIKE ApplyStoreLocale, BUT FOR A DIFFERENT
 * AND INDEPENDENTLY VERIFIED REASON: a panel page's own GET request runs
 * through AdminPanelProvider's ->middleware([...]) array and never through
 * Laravel's global 'web' group, while EVERY Livewire round trip afterwards
 * (a table sort, a pagination click, a form save) is a POST to Livewire's
 * own update endpoint, which the installed Livewire registers with exactly
 * `->middleware(['web', RequireLivewireHeaders::class])`
 * (vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php,
 * boot()/setUpdateRoute()) — a pipeline no Filament panel contributes to.
 * Panel-only registration would therefore lose the merchant's time zone on
 * the second click of every screen; 'web'-group-only registration would
 * leave the first render of every panel page in UTC. Both are needed for
 * the setting to hold for a whole session.
 *
 * No caching: as with ApplyStoreLocale, site-settings-design.md §8 is
 * explicit that one indexed lookup by primary key is not a performance
 * concern, and EloquentSiteSettingsRepository already memoizes per request.
 */
class ApplyStoreTimezone
{
    public function __construct(
        private readonly StoreTimezone $storeTimezone,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The two-layer fallback site-settings-design.md §5 prescribes: the
        // stored row wins, and the code-level default lives with the
        // feature (config/services.php, this project's established place for
        // a developer-settable, env-overridable default) — never in this
        // class, and never as a Bulgaria-specific constant in the code path.
        // The service layer reads the same answer through StoreTimezone (refunds R3), so a screen and a
        // service can never disagree about the store's zone.
        FilamentTimezone::set($this->storeTimezone->current());

        return $next($request);
    }
}
