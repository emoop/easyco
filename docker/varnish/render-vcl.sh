#!/bin/sh
# Renders rules.vcl from rules.vcl.tpl: replaces the @@NAME@@ numbers with the VARNISH_LIMIT_* environment variables (defaults below).
#
#   render-vcl.sh <template-dir> <out-dir>
#
# Every value must be a whole number >= 1: the number is pasted into VCL, so nothing else is ever accepted (no way to inject VCL through an environment variable).
#
# The defaults are CONSERVATIVE PLACEHOLDERS, not measured numbers: nobody has measured what php-fpm of the production server can do yet. They are tuned in P8e.
#   per IP, per WINDOW seconds:  filtered listings 60, search 20, card-sizes (cache MISSES only) 30
#   GLOBAL (all IPs together), per WINDOW seconds:  filtered listings 300, search 120
#   size limits: a URL over VARNISH_MAX_URL (4096) characters is 414, a Cookie header over VARNISH_MAX_COOKIE (6144) is 431 (the tests render small ones)
set -eu

tpl_dir="${1:?usage: render-vcl.sh <template-dir> <out-dir>}"
out_dir="${2:?usage: render-vcl.sh <template-dir> <out-dir>}"

: "${VARNISH_LIMIT_WINDOW:=60}"
: "${VARNISH_LIMIT_IP_FILTER:=60}"
: "${VARNISH_LIMIT_IP_SEARCH:=20}"
: "${VARNISH_LIMIT_IP_CARDSIZES:=30}"
: "${VARNISH_LIMIT_GLOBAL_FILTER:=300}"
: "${VARNISH_LIMIT_GLOBAL_SEARCH:=120}"
: "${VARNISH_MAX_URL:=4096}"
: "${VARNISH_MAX_COOKIE:=6144}"

check() {
    case "$2" in
        ''|*[!0-9]*|0|0[0-9]*)
            echo "render-vcl: $1 must be a whole number of at least 1, got '$2'" >&2
            exit 1
            ;;
    esac
    if [ "${#2}" -gt 9 ]; then
        echo "render-vcl: $1 is too large: '$2'" >&2
        exit 1
    fi
}

check VARNISH_LIMIT_WINDOW "$VARNISH_LIMIT_WINDOW"
check VARNISH_LIMIT_IP_FILTER "$VARNISH_LIMIT_IP_FILTER"
check VARNISH_LIMIT_IP_SEARCH "$VARNISH_LIMIT_IP_SEARCH"
check VARNISH_LIMIT_IP_CARDSIZES "$VARNISH_LIMIT_IP_CARDSIZES"
check VARNISH_LIMIT_GLOBAL_FILTER "$VARNISH_LIMIT_GLOBAL_FILTER"
check VARNISH_LIMIT_GLOBAL_SEARCH "$VARNISH_LIMIT_GLOBAL_SEARCH"

# the VCL regex is `^.{N,}` with N = the limit + 1; a regex repeat count must stay under 65535
check VARNISH_MAX_URL "$VARNISH_MAX_URL"
check VARNISH_MAX_COOKIE "$VARNISH_MAX_COOKIE"
if [ "$VARNISH_MAX_URL" -gt 60000 ] || [ "$VARNISH_MAX_COOKIE" -gt 60000 ]; then
    echo "render-vcl: VARNISH_MAX_URL and VARNISH_MAX_COOKIE must be at most 60000" >&2
    exit 1
fi
url_over=$((VARNISH_MAX_URL + 1))
cookie_over=$((VARNISH_MAX_COOKIE + 1))

# only a directory THIS script creates is touched (never a pre-existing one: /tmp, or /etc/varnish in the container, which the caller owns or may not own)
created=0
[ -d "$out_dir" ] || created=1
mkdir -p "$out_dir"
sed \
    -e "s/@@WINDOW@@/$VARNISH_LIMIT_WINDOW/g" \
    -e "s/@@LIMIT_IP_FILTER@@/$VARNISH_LIMIT_IP_FILTER/g" \
    -e "s/@@LIMIT_IP_SEARCH@@/$VARNISH_LIMIT_IP_SEARCH/g" \
    -e "s/@@LIMIT_IP_CARDSIZES@@/$VARNISH_LIMIT_IP_CARDSIZES/g" \
    -e "s/@@LIMIT_GLOBAL_FILTER@@/$VARNISH_LIMIT_GLOBAL_FILTER/g" \
    -e "s/@@LIMIT_GLOBAL_SEARCH@@/$VARNISH_LIMIT_GLOBAL_SEARCH/g"     -e "s/@@URL_OVER@@/$url_over/g"     -e "s/@@COOKIE_OVER@@/$cookie_over/g" \
    "$tpl_dir/rules.vcl.tpl" > "$out_dir/rules.vcl"

# readable by the user that compiles it (varnishd runs as `varnish`, whoever rendered the file): the file, and the directory only when we made it
[ "$created" -eq 1 ] && chmod 0755 "$out_dir"
chmod 0644 "$out_dir/rules.vcl"

# a placeholder that is left would be a VCL syntax error at start: say so here, clearly (the explanatory comment in the template mentions `@@NAME@@` once, which is not a placeholder)
if grep -E '@@[A-Z_]+@@' "$out_dir/rules.vcl" | grep -v 'the @@NAME@@' > /dev/null; then
    echo "render-vcl: a placeholder was not replaced in $out_dir/rules.vcl" >&2
    exit 1
fi
