#!/bin/sh
# EasyCo varnish container: render the limits into the VCL, then start varnish exactly as the official image does (its own entrypoint, its own flags).
set -eu

easyco-render-vcl /etc/varnish /etc/varnish

if command -v docker-varnish-entrypoint > /dev/null 2>&1; then
    exec docker-varnish-entrypoint "$@"
fi

# the official entrypoint is not where we expect it: start varnishd with the flags that image uses (a fallback, the CI smoke test would show it)
exec varnishd -F -f /etc/varnish/default.vcl -a http=:80,HTTP -a proxy=:8443,PROXY -p feature=+http2 -s "malloc,${VARNISH_SIZE:-512m}" "$@"
