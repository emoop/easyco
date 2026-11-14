vcl 4.1;

# EasyCo (DOCKER-1): varnish in FRONT of nginx. THIS FILE IS TRANSPARENT ON PURPOSE: it passes EVERYTHING to the backend and caches nothing.
#
#   P8 REPLACES THE PASS-ALL BELOW with real rules (what is cached, for how long, which cookies / carts bypass, purge by tag).
#   Until then varnish only forwards, keeps the client IP and leaves the X-Forwarded-Proto header of the outer proxy as it is.

backend default {
    .host = "web";
    .port = "80";
    .connect_timeout = 5s;
    .first_byte_timeout = 300s;      # slow admin screens and big uploads are not cut by the cache layer
    .between_bytes_timeout = 60s;
}

# Who may PURGE / BAN (P8): only the internal Docker networks.
acl purge {
    "localhost";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_recv {
    # PURGE / BAN: the ACL alone is NOT enough here. The outer proxy (Coolify) is on an internal network too, so a request from the internet
    # arrives from an "internal" address. A purge sent from inside the stack carries no X-Forwarded-For; one that came through the outer
    # proxy always does. Both conditions are checked BEFORE our own X-Forwarded-For is added below.
    # NO PURGE LOGIC YET (P8): an allowed caller gets 501, everyone else 403.
    if (req.method == "PURGE" || req.method == "BAN") {
        if (!client.ip ~ purge || req.http.X-Forwarded-For) {
            return (synth(403, "Forbidden"));
        }
        return (synth(501, "Purge is not implemented until P8"));
    }

    # Keep the visitor's IP: append the address we were called from.
    if (req.http.X-Forwarded-For) {
        set req.http.X-Forwarded-For = req.http.X-Forwarded-For + ", " + client.ip;
    } else {
        set req.http.X-Forwarded-For = client.ip;
    }

    return (pass);
}
