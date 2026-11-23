#!/bin/sh
# CRM7 Dockerless VPS — static config validation.
# Runs without installing anything. Optional binary checks are skipped when
# nginx/apachectl are not installed.
set -u
DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
P=0; F=0; S=0
pass(){ P=$((P+1)); printf 'PASS | %s\n' "$1"; }
fail(){ F=$((F+1)); printf 'FAIL | %s\n' "$1"; }
skip(){ S=$((S+1)); printf 'SKIP | %s | %s\n' "$1" "$2"; }

# POSIX shell syntax.
for f in "$DIR/cron.sh" "$DIR/tests/http-smoke.sh" "$DIR/tests/cron-wrapper-tests.sh" "$DIR/tests/config-check.sh"; do
    if sh -n "$f" 2>/dev/null; then pass "sh -n $(basename "$f")"; else fail "sh -n $(basename "$f")"; fi
done
if command -v dash >/dev/null 2>&1; then
    if dash -n "$DIR/cron.sh" 2>/dev/null; then pass 'dash -n cron.sh'; else fail 'dash -n cron.sh'; fi
else
    skip 'dash -n cron.sh' 'dash not installed'
fi

# Required directives in the shipped templates.
assert_contains() {
    file=$1; pattern=$2; label=$3
    if grep -q "$pattern" "$file" 2>/dev/null; then pass "$label"; else fail "$label"; fi
}
assert_contains "$DIR/nginx-root.conf" 'fastcgi_param SCRIPT_FILENAME /srv/crm7/api/public/index.php' 'nginx root: fixed SCRIPT_FILENAME'
assert_contains "$DIR/nginx-root.conf" 'fastcgi_param HTTP_X_FORWARDED_FOR ""' 'nginx root: XFF cleared'
assert_contains "$DIR/nginx-subdir.conf" 'fastcgi_param SCRIPT_FILENAME /srv/crm7/demo/api/public/index.php' 'nginx subdir: fixed SCRIPT_FILENAME'
assert_contains "$DIR/nginx-subdir.conf" 'fastcgi_param HTTP_X_FORWARDED_FOR ""' 'nginx subdir: XFF cleared'
assert_contains "$DIR/apache-root.conf" 'SetEnvIfNoCase ^Authorization\$' 'apache: Authorization forwarded'
assert_contains "$DIR/apache-root.conf" 'RequestHeader unset X-Forwarded-For' 'apache: XFF removed'
assert_contains "$DIR/apache-root.conf" 'Require all denied' 'apache: parent directory denied'
assert_contains "$DIR/cron.sh" 'FLOCK_BIN" -n' 'cron: non-blocking flock'
assert_contains "$DIR/cron.sh" 'exec .*CRM7_PHP_BIN' 'cron: exec preserves PHP exit code'

# Optional native syntax checks.
if command -v nginx >/dev/null 2>&1; then
    # A server block alone is not a full nginx.conf; report as informational.
    skip 'nginx -t' 'template is a server block, validate inside target nginx.conf'
else
    skip 'nginx -t' 'nginx not installed'
fi
if command -v apachectl >/dev/null 2>&1; then
    skip 'apachectl configtest' 'template is a vhost, validate inside target httpd.conf'
else
    skip 'apachectl configtest' 'apachectl not installed'
fi

printf 'SUMMARY pass=%s fail=%s skip=%s\n' "$P" "$F" "$S"
[ "$F" -eq 0 ] || exit 1
