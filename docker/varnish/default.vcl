vcl 4.1;

# EasyCo (P8c-1): varnish in FRONT of nginx. This file only names the backends; ALL the rules are in rules.vcl, which the container RENDERS at start from rules.vcl.tpl
# (the limits come from VARNISH_LIMIT_* environment variables, see render-vcl.sh) and which the varnishtest files include as well, so the tests run the very rules that ship.
#
# Design: packages/EasyCo/documents/storefront-cache-contract.md (what the app sends) and the P8 design §5 (what this layer does).

# TWO backends to the SAME host and port, on purpose:
#
#   default  has NO probe. EVERY fetch goes through it (a miss, a pass: /cart, /checkout, /admin, a search). A probe can therefore never block traffic: when the app is overloaded
#            the pages are slow, they are never a 503 that Varnish decided on its own.
#   health   has the probe and is NEVER used to fetch anything. rules.vcl only asks `std.healthy(health)`: «is the app answering?». The answer decides one thing: whether an OLD copy
#            (the 24 h grace of a stored page) may be served. Healthy = an expired or purged page is fetched fresh at once; sick = the old copy is allowed.
backend default {
    .host = "web";
    .port = "80";
    .connect_timeout = 5s;
    .first_byte_timeout = 300s;      # slow admin screens and big uploads are not cut by the cache layer
    .between_bytes_timeout = 60s;
}

backend health {
    .host = "web";
    .port = "80";

    # The numbers decide how soon a dead app is noticed (and so how soon an old page may be shown instead of waiting for it).
    #   GET /up   Laravel's own health route: light, open (the site password does not cover it). The Host is `127.0.0.1`, the SAME one the compose healthcheck of `web` sends
    #             (`wget http://127.0.0.1/up`), which is known to be answered 200 on the server. The app has no trusted-hosts list and no domain routing (checked: no TrustHosts,
    #             no Route::domain, no host test in app/, bootstrap/, config/, routes/), and nginx's only server block is `server_name _`, so no Host is refused anyway.
    #   .interval 5s, .timeout 4s   a probe a few seconds apart; one that takes longer than 4 s counts as failed (nothing here is slower than that when the app is well)
    #   .window 5, .threshold 3     the last 5 probes are looked at; the app is healthy while at least 3 of them are good, so it turns sick after 3 bad ones (about 15 s)
    #                               and healthy again after 3 good ones: one slow answer never flips it
    #   .initial 3                  counts as 3 good probes at start, so a freshly started varnish is healthy at once
    # If this probe is wrong (a path nginx does not answer 200, a Host it refuses) the app is only seen as SICK: old copies of cached pages may then be shown for up to a day after an
    # edit, and NOTHING is ever refused because of it. Check on the server:  docker compose exec varnish varnishadm backend.list   (the row `health` must say healthy)
    .probe = {
        .request =
            "GET /up HTTP/1.1"
            "Host: 127.0.0.1"
            "Connection: close"
            "User-Agent: varnish-probe";
        .interval = 5s;
        .timeout = 4s;
        .window = 5;
        .threshold = 3;
        .initial = 3;
    }
}

include "rules.vcl";
