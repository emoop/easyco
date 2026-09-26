<?php

namespace App\Providers;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Persistence\Eloquent\EloquentSiteSettingsRepository;
use Illuminate\Support\ServiceProvider;

class SiteSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), NOT bind() and NOT singleton() — and the difference is
        // load-bearing, not stylistic: this repository now memoizes every key it
        // reads for the lifetime of its instance, so
        //
        //  - bind() (one fresh instance per app() call) would make the memo
        //    useless: each caller's first read would query again, which is
        //    exactly the 19-reads-per-render hot spot this fixes;
        //  - singleton() would keep the memo for the LIFE OF THE PROCESS, so a
        //    long-running worker (or an Octane-style runtime) would keep serving
        //    a setting forever after the first read, and a merchant's own
        //    settings change would never take effect;
        //  - scoped() gives one instance per request/job: every caller in a
        //    request shares the reads, and the next request starts clean and
        //    reads the current values. Same lifetime reasoning as
        //    AppServiceProvider's own scoped() bindings
        //    (AuthenticatedStaffResolver and friends).
        $this->app->scoped(SiteSettingsRepository::class, EloquentSiteSettingsRepository::class);
    }
}
