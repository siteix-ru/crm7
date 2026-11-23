#!/bin/sh
# CRM7 Dockerless VPS — HTTP smoke and security checks.
#
# Usage:
#   sh http-smoke.sh <base-url> <api-prefix> [login] [password] [web-prefix]
# Example:
#   sh http-smoke.sh http://127.0.0.1:18280 /api/v2 admin Secret123
#   sh http-smoke.sh http://127.0.0.1:18281 /demo/api/v2 admin Secret123 /demo
#
# The environment under test must contain the canary fixtures described in
# deploy/dockerless/README.md (public/.env, public/canary.php, api/storage/canary.txt,
# api/var/log/app.log, backups/canary.txt, api/config/database.json.bak).
# Exit code: 0 = all checks passed, 1 = at least one check failed.

set -u
BASE=${1:?base url required}
API=${2:?api prefix required, e.g. /api/v2}
LOGIN=${3:-}
PASSWORD=${4:-}
WEB=${5:-}
PASS=0
FAIL=0

check() {
    name=$1; url=$2; expect_code=$3; expect_body=$4; forbidden=$5
    body=$(curl -sS --max-time 10 "$url" 2>/dev/null)
    code=$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' "$url" 2>/dev/null)
    bad=0
    case "$expect_code" in
        *"$code"*) ;;
        *) bad=1 ;;
    esac
    if [ -n "$expect_body" ] && ! printf '%s' "$body" | grep -q "$expect_body"; then bad=1; fi
    if [ -n "$forbidden" ] && printf '%s' "$body" | grep -q "$forbidden"; then bad=1; fi
    if [ "$bad" -eq 0 ]; then PASS=$((PASS+1)); printf 'PASS | %s | %s\n' "$name" "$code";
    else FAIL=$((FAIL+1)); printf 'FAIL | %s | code=%s body=%s\n' "$name" "$code" "$body"; fi
}

API_ROOT=${API%/v2}

check 'health'            "$BASE$API/health"                        '200' '"status":"ok"' ''
check 'SPA index'         "$BASE$WEB/"                              '200' 'ROOT_FRONTEND_OK' ''
check 'config.js'         "$BASE$WEB/config.js"                     '200' 'apiV2Base' ''
check 'assets'            "$BASE$WEB/assets/app.js"                 '200' 'ASSET_OK' ''
check 'source maps absent' "$BASE$WEB/assets/fake.js.map"           '403 404' '' 'SECRET_SOURCE_MAP'
check 'unknown API route' "$BASE$API/no-such-route"                 '404' '' ''
check 'dotfile'           "$BASE$WEB/%2eenv"                        '403 404' '' 'DOTFILE_CANARY'
check 'PHP in docroot'    "$BASE$WEB/canary.php"                    '403 404' '' 'PHP_EXEC_CANARY'
check 'api .env'          "$BASE$API_ROOT/config/.env"              '403 404' '' 'APP_KEY'
check 'api database.bak'  "$BASE$API_ROOT/config/database.json.bak" '403 404' '' 'DB_BAK_CANARY'
check 'api src'           "$BASE$API_ROOT/src/Core/Database.php"    '403 404' '' 'class Database'
check 'api bin'           "$BASE$API_ROOT/bin/cron.php"             '403 404' '' 'processQueue'
check 'api tests'         "$BASE$API_ROOT/tests/demo-mode-guard.php" '403 404' '' 'require'
check 'api storage'       "$BASE$API_ROOT/storage/canary.txt"       '403 404' '' 'STORAGE_CANARY'
check 'api var/log'       "$BASE$API_ROOT/var/log/app.log"          '403 404' '' 'LOG_CANARY'
check 'backups'           "$BASE/backups/canary.txt"                '403 404' '' 'BACKUP_CANARY'
check 'traversal ..'      "$BASE$API/../config/.env"                '403 404' '' 'APP_KEY'
check 'traversal encoded' "$BASE$API/%2e%2e/config/.env"            '403 404' '' 'APP_KEY'
check 'arbitrary PHP API' "$BASE$API/canary.php"                    '403 404' '' 'PHP_EXEC_CANARY'
check 'router.php'        "$BASE$API_ROOT/router.php"               '403 404' '' 'require __DIR__'

if [ -n "$LOGIN" ] && [ -n "$PASSWORD" ]; then
    login_body=$(printf '{"login":"%s","password":"%s"}' "$LOGIN" "$PASSWORD")
    login=$(curl -sS --max-time 10 -X POST -H 'Content-Type: application/json' \
        --data "$login_body" "$BASE$API/auth/login" 2>/dev/null)
    token=$(printf '%s' "$login" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
    if [ -n "$token" ]; then
        me=$(curl -sS --max-time 10 -H "Authorization: Bearer $token" "$BASE$API/auth/me" 2>/dev/null)
        if printf '%s' "$me" | grep -q '"ok":true'; then
            PASS=$((PASS+1)); printf 'PASS | bearer auth (login + /auth/me) | 200\n'
        else
            FAIL=$((FAIL+1)); printf 'FAIL | bearer auth (login + /auth/me) | %s\n' "$me"
        fi
    else
        FAIL=$((FAIL+1)); printf 'FAIL | login did not return a token | %s\n' "$login"
    fi
else
    printf 'SKIP | bearer auth | no credentials supplied\n'
fi

printf 'SUMMARY pass=%s fail=%s\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
exit 0
