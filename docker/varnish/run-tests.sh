#!/bin/sh
# Runs the varnishtest files against the RENDERED rules. Needs `varnishtest` (it is in the varnish image the stack uses; CI runs it there, so does the owner):
#
#   docker build --target varnish -t easyco-varnish:test .
#   docker run --rm --user root --entrypoint /work/run-tests.sh -v "$PWD/docker/varnish:/work:ro" easyco-varnish:test
#
# Two renderings: the DEFAULT limits (every test but the limit tests) and LOW limits (the 429 / 414 / 431 tests, so a handful of requests reach the cap).
#   vcldir = default rendering, vcllow = low-limit rendering. The LOW one also has tiny size limits (URL 100, Cookie 120), so the over-limit strings in limits.vtc are short literals
#   (varnishtest cannot hold a 4000-character macro: its macro buffer overflows).
set -eu

here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
# mktemp makes the directory 0700 (owned by whoever runs this, root in the CI container), but varnishd compiles the VCL as the unprivileged `varnish` user: it must be able to read it
chmod 0755 "$work"

"$here/render-vcl.sh" "$here" "$work/default"

VARNISH_LIMIT_WINDOW=60 \
VARNISH_LIMIT_IP_FILTER=2 \
VARNISH_LIMIT_IP_SEARCH=2 \
VARNISH_LIMIT_IP_CARDSIZES=2 \
VARNISH_LIMIT_GLOBAL_FILTER=4 \
VARNISH_LIMIT_GLOBAL_SEARCH=4 \
VARNISH_MAX_URL=100 \
VARNISH_MAX_COOKIE=120 \
    "$here/render-vcl.sh" "$here" "$work/low"

# the rendered directories and files readable by everybody (render-vcl.sh sets them too; this is the belt for the braces)
chmod -R a+rX "$work"

exec varnishtest -j 4 \
    -D "vcldir=$work/default" \
    -D "vcllow=$work/low" \
    "$here"/tests/*.vtc
