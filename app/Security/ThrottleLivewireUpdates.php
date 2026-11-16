<?php

namespace App\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** P8d: the `livewire` limiter on the Livewire update route only (see SecurityLimiters); every other `web` request passes untouched. */
final class ThrottleLivewireUpdates
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();

        if (! str_ends_with($name, 'livewire.update')) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, 'livewire');
    }
}
