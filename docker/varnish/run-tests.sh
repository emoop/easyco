#!/bin/sh
# Runs the varnishtest files against the RENDERED rules. Needs `varnishtest` (it is in the varnish image the stack uses; CI runs it there, so does the owner):
#
#   docker build --target varnish -t easyco-varnish:test .
#   docker run --rm --user root --entrypoint /work/run-tests.sh -v "$PWD/docker/varnish:/work:ro" easyco-varnish:test
#
# Two renderings: the DEFAULT limits (every test but the limit tests) and LOW limits (the 429 tests, so a handful of requests reach the cap).
#   vcldir = default rendering, vcllow = low-limit rendering, longurl / longcookie = strings over the size limits (4096 for the URL, 6144 for the Cookie header).
set -eu

here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"$here/render-vcl.sh" "$here" "$work/default"

VARNISH_LIMIT_WINDOW=60 \
VARNISH_LIMIT_IP_FILTER=2 \
VARNISH_LIMIT_IP_SEARCH=2 \
VARNISH_LIMIT_IP_CARDSIZES=2 \
VARNISH_LIMIT_GLOBAL_FILTER=4 \
VARNISH_LIMIT_GLOBAL_SEARCH=4 \
    "$here/render-vcl.sh" "$here" "$work/low"

longurl=$(head -c 4200 /dev/zero | tr '\0' 'a')
longcookie=$(head -c 6300 /dev/zero | tr '\0' 'a')

exec varnishtest -j 4 \
    -D "vcldir=$work/default" \
    -D "vcllow=$work/low" \
    -D "longurl=$longurl" \
    -D "longcookie=$longcookie" \
    "$here"/tests/*.vtc
