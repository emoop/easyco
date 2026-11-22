# EasyCo (P8c-1): the cache rules. A TEMPLATE: render-vcl.sh replaces the @@NAME@@ numbers (checked: digits only) and writes rules.vcl. No `vcl 4.1;` line and no backend
# here: default.vcl (production) and the varnishtest files (-vcl+backend) provide them and include the rendered file.
#
# What the app sends (storefront-cache-contract.md §1): a cacheable page is a 200/301 with `Cache-Control: public, max-age=0, s-maxage=...` and an `xkey` header; everything
# else is left private. This file decides which REQUESTS may be answered from the cache at all, and keeps nothing the app did not mark.

import std;
import var;
import xkey;
import vsthrottle;

# Who may PURGE: only the internal Docker networks.
acl purge {
    "localhost";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_recv {
    # ---- 1. methods ---------------------------------------------------------------------------------------------------------------------------------
    if (req.method == "PURGE") {
        # The ACL alone is NOT enough: the outer proxy (Coolify) is on an internal network too, so a request from the internet arrives from an "internal" address.
        # A purge sent from inside the stack carries no X-Forwarded-For; one that came through the outer proxy always does. Checked BEFORE our own X-Forwarded-For below.
        if (!client.ip ~ purge || req.http.X-Forwarded-For) {
            return (synth(403, "Forbidden"));
        }

        if (!req.http.xkey-softpurge) {
            return (synth(400, "Missing xkey-softpurge header"));
        }

        # SOFT purge: the objects keep their grace, but vcl_recv uses it ONLY while the backend is sick: with a healthy backend the next request fetches the fresh page (one fetch per object).
        set req.http.X-EC-Purged = xkey.softpurge(req.http.xkey-softpurge);

        return (synth(200, "Purged " + req.http.X-EC-Purged));
    }

    # BAN and everything we do not know is refused. OPTIONS is passed through (a browser may ask before a cross-origin API call).
    if (req.method != "GET" && req.method != "HEAD" && req.method != "POST" && req.method != "PUT" && req.method != "PATCH" && req.method != "DELETE" && req.method != "OPTIONS") {
        return (synth(405, "Method Not Allowed"));
    }

    # ---- 2. size limits: a URL longer than VARNISH_MAX_URL (4096) and a Cookie header longer than VARNISH_MAX_COOKIE (6144) are refused (varnishd's own caps stay: http_req_hdr_len 8k per header, http_req_size 32k) ------------------------------------------------------
    if (req.url ~ "^.{@@URL_OVER@@,}") {
        return (synth(414, "URI Too Long"));
    }

    if (req.http.Cookie ~ "^.{@@COOKIE_OVER@@,}") {
        return (synth(431, "Request Header Fields Too Large"));
    }

    # ---- 3. the client's address ---------------------------------------------------------------------------------------------------------------------
    # `var ip` = the REAL client, for the per-IP limits. The outer proxy (Coolify / Traefik) APPENDS the address it saw to X-Forwarded-For, so the RIGHT-most entry is the one
    # our own proxy wrote; everything to its left was supplied by the client (or earlier hops) and can be anything, so it is never used. This is only true while the outer proxy is
    # the ONLY way in (the compose file publishes no port of this service): a client that could reach varnish directly could write the right-most entry itself.
    if (req.http.X-Forwarded-For) {
        var.set("ip", regsub(req.http.X-Forwarded-For, "^.*[, ]([^, ]+)$", "\1"));
        set req.http.X-Forwarded-For = req.http.X-Forwarded-For + ", " + client.ip;
    } else {
        set req.http.X-Forwarded-For = client.ip;
        var.set("ip", req.http.X-Forwarded-For);
    }

    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # ---- 4. what is never cached ---------------------------------------------------------------------------------------------------------------------
    # Search and filtered listings reach the backend EVERY time, so they are the expensive class: a per-IP limit and a GLOBAL cap (one constant key: a botnet of many IPs
    # shares it). Cached pages are not counted: only these two classes pass through here.
    if (req.url ~ "(?i)^/search([/?]|$)" || req.url ~ "[?&]q(=|&|\[|%5[Bb]|$)") {
        if (vsthrottle.is_denied("search:" + var.get("ip"), @@LIMIT_IP_SEARCH@@, @@WINDOW@@s) || vsthrottle.is_denied("search:*", @@LIMIT_GLOBAL_SEARCH@@, @@WINDOW@@s)) {
            return (synth(429, "Too Many Requests"));
        }

        return (pass);
    }

    if (req.url ~ "[?&]filter(=|&|\[|%5[Bb]|$)") {
        if (vsthrottle.is_denied("filter:" + var.get("ip"), @@LIMIT_IP_FILTER@@, @@WINDOW@@s) || vsthrottle.is_denied("filter:*", @@LIMIT_GLOBAL_FILTER@@, @@WINDOW@@s)) {
            return (synth(429, "Too Many Requests"));
        }

        return (pass);
    }

    # Paths of the app that are private or per visitor (storefront-cache-contract.md §1 «Pass»; `__ec` = the bot trap, the previews are signed URLs).
    if (req.url ~ "(?i)^/(admin|livewire|_sandbox)" || req.url ~ "(?i)^/(api|cart|checkout|order|account|__ec|design-preview|footer-preview|header-preview|info-preview|csp-report|up)([/?]|$)") {
        return (pass);
    }

    # A signed preview (any page) and the Design screen's preview zone.
    if (req.url ~ "[?&](signature|expires|zone)(=|&|$)") {
        return (pass);
    }

    # ---- 5. everything else may be cached: the app decides (s-maxage), the cookies and the query are cleaned -------------------------------------------------
    unset req.http.Cookie;

    # Query hygiene: ONLY page, per_page, sort, featured and ids stay (the app reads nothing else on a cacheable page). Everything else, utm_*, gclid, fbclid, msclkid and
    # every unknown name, is removed from the key AND from the request the backend gets (the browser keeps its own URL, so a Pixel still reads fbclid). `&name` segments whose
    # name is not on the list are cut; then the order is normalised, so ?sort=a&page=2 and ?page=2&sort=a are one object.
    if (req.url ~ "\?") {
        set req.http.X-EC-Path = regsub(req.url, "\?.*$", "");
        set req.http.X-EC-Query = regsub(regsuball("&" + regsub(req.url, "^[^?]*\?", ""), "&(?!(?:page|per_page|sort|featured|ids)(?:=|&|$))[^&]*", ""), "^&", "");

        if (req.http.X-EC-Query != "") {
            set req.url = std.querysort(req.http.X-EC-Path + "?" + req.http.X-EC-Query);
        } else {
            set req.url = req.http.X-EC-Path;
        }

        unset req.http.X-EC-Path;
        unset req.http.X-EC-Query;
    }

    # STALE ONLY WHEN THE APP IS NOT ANSWERING. `health` is the backend that carries the probe (default.vcl); it is only ASKED here, never used to fetch, so a sick answer can
    # never make a request fail: all fetches go through `default`, which has no probe. While the app is healthy no grace is used: an expired or soft-purged object is a miss and the
    # visitor gets the fresh page (the waiting list still sends ONE fetch per object, so no stampede). When the probe says sick, the object's own 24 h grace applies.
    if (std.healthy(health)) {
        set req.grace = 0s;
    }

    return (hash);
}

sub vcl_hash {
    # STAGING BASIC AUTH (security-critical). The app answers 401 to a request without the site password, but a page it answered 200 to a request WITH it carries s-maxage and
    # would be handed to anybody from the cache. The Authorization header therefore is part of the key: a request without it (or with a wrong one) is looked up under its OWN
    # key, misses, and the app answers 401, which is never cached. The builtin vcl_hash (URL + host) still runs after this. With no basic auth (production) no request carries
    # the header and nothing changes.
    if (req.http.Authorization) {
        hash_data(req.http.Authorization);
    }
}

sub vcl_miss {
    # The cacheable class that is cheap to HIT and costly to MISS: the card sizes (a bot walking ids). Only a MISS is counted.
    if (req.url ~ "^/storefront/card-sizes([/?]|$)") {
        if (vsthrottle.is_denied("cardsizes:" + var.get("ip"), @@LIMIT_IP_CARDSIZES@@, @@WINDOW@@s)) {
            return (synth(429, "Too Many Requests"));
        }
    }
}

sub vcl_backend_response {
    # Cache only a 200 / 301 that the app marked with s-maxage (>= 1) and that has no Set-Cookie and is not private / no-store. TTL = s-maxage (the app sends 7 days), at most 7 days.
    if ((beresp.status == 200 || beresp.status == 301)
        && beresp.http.Cache-Control ~ "(?i)s-maxage=[1-9][0-9]*"
        && beresp.http.Cache-Control !~ "(?i)(no-store|private)"
        && !beresp.http.Set-Cookie) {
        set beresp.ttl = std.duration(regsub(beresp.http.Cache-Control, "(?i)^.*s-maxage=([0-9]+).*$", "\1") + "s", 0s);

        if (beresp.ttl > 7d) {
            set beresp.ttl = 7d;
        }

        # 1 day of grace: protection against an OUTAGE. A stale copy is served (and refreshed in the background) only while the `health` probe says sick (see vcl_recv: a healthy app gets grace 0).
        set beresp.grace = 24h;

        return (deliver);
    }

    # Anything else (404, 5xx, a page with a cookie, no s-maxage, a 401) is remembered as «do not cache» for a short time (hit-for-miss), so the next request is not queued behind it.
    set beresp.uncacheable = true;
    set beresp.ttl = 120s;

    # a server error and a 401 (the site password: a bot can send endless different Authorization values, each one its own key) are remembered only briefly
    if (beresp.status >= 500 || beresp.status == 401) {
        set beresp.ttl = 10s;
    }

    return (deliver);
}

sub vcl_deliver {
    # X-Cache on EVERY response (dev and production); Age stays.
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }

    unset resp.http.xkey;
    unset resp.http.Via;
    unset resp.http.X-Varnish;
}

sub vcl_synth {
    # a synthetic answer is never a HIT (and does not pass vcl_deliver)
    set resp.http.X-Cache = "MISS";

    if (resp.status == 429) {
        set resp.http.Retry-After = "@@WINDOW@@";
    }

    if (resp.status == 405) {
        set resp.http.Allow = "GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS";
    }
}
