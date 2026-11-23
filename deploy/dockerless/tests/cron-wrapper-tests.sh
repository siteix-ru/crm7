#!/bin/sh
# CRM7 Dockerless VPS — cron wrapper behavioral tests.
# Runs against a disposable filesystem only; never touches a real CRM7 install.
# Usage: sh cron-wrapper-tests.sh /path/to/deploy/dockerless/cron.sh

set -u
WRAPPER=${1:?path to cron.sh required}
TMP=$(mktemp -d /tmp/crm7-cron-test.XXXXXX) || exit 1
trap 'rm -rf "$TMP"' EXIT INT TERM
mkdir -p "$TMP/var/log" "$TMP/api/bin" "$TMP/fakebin"
: > "$TMP/runs.log"
: > "$TMP/api/bin/cron.php"

cat > "$TMP/fakebin/php" <<'PHP'
#!/bin/sh
printf 'START %s %s\n' "${FAKE_TAG:-x}" "$*" >> "$FAKE_LOG"
sleep "${FAKE_DELAY:-0}"
printf 'DONE %s\n' "${FAKE_TAG:-x}" >> "$FAKE_LOG"
exit "${FAKE_EXIT:-0}"
PHP
chmod +x "$TMP/fakebin/php"

export FAKE_LOG="$TMP/runs.log"
PHP_BIN="$TMP/fakebin/php"
LOCK="$TMP/var/crm7-cron.lock"

run() {
    label=$1; shift
    "$@" >/dev/null 2>&1
    printf '%s=%s\n' "$label" "$?"
}

run success env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=ok sh "$WRAPPER"
log_mode=$(stat -c %a "$TMP/var/log/cron.log" 2>/dev/null || printf 'unknown')
lock_mode=$(stat -c %a "$LOCK" 2>/dev/null || printf 'unknown')
printf 'log_mode=%s lock_mode=%s\n' "$log_mode" "$lock_mode"
if [ "$log_mode" = "600" ] && [ "$lock_mode" = "600" ]; then
  printf '%s\n' 'PASS | cron file modes are 0600'
else
  printf '%s\n' 'FAIL | cron file modes are not 0600'
fi
printf '%s\n' '--- expected log_mode=600 lock_mode=600 ---'
run exit42 env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=exit42 FAKE_EXIT=42 sh "$WRAPPER"
run no_php env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$TMP/nope" sh "$WRAPPER"
run no_flock env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" CRM7_FLOCK_BIN="$TMP/nope" sh "$WRAPPER"
run no_script env CRM7_ROOT="$TMP/var" CRM7_PHP_BIN="$PHP_BIN" sh "$WRAPPER"
run lock_bad env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" CRM7_CRON_LOCK="$TMP/var" sh "$WRAPPER"
run log_bad env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" CRM7_CRON_LOG="$TMP/var" sh "$WRAPPER"

env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=long FAKE_DELAY=5 sh "$WRAPPER" >/dev/null 2>&1 &
first=$!
sleep 1
run concurrent_second env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=second sh "$WRAPPER"
wait "$first"

run after_release env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=third sh "$WRAPPER"

# SIGTERM to a running fake PHP, then a new run must acquire the lock again.
env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=sig FAKE_DELAY=10 sh "$WRAPPER" >/dev/null 2>&1 &
sig=$!
sleep 1
kill -TERM "$sig" 2>/dev/null || true
wait "$sig" 2>/dev/null || true
sleep 1
run after_signal_child_holds_lock env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=after_sig sh "$WRAPPER"
sleep 11
run after_child_exit env CRM7_ROOT="$TMP" CRM7_PHP_BIN="$PHP_BIN" FAKE_TAG=after_child sh "$WRAPPER"

printf '%s\n' '--- fake PHP invocations ---'
cat "$TMP/runs.log"
printf '%s\n' '--- lock file ---'
ls -l "$LOCK" 2>/dev/null || printf 'LOCK MISSING\n'
printf '%s\n' '--- expected codes: success=0 exit42=42 no_php=69 no_flock=69 no_script=69 lock_bad=73 log_bad=74 concurrent_second=75 after_release=0 after_signal_child_holds_lock=75 after_child_exit=0 ---'
printf '%s\n' '--- after_signal_child_holds_lock=75 is EXPECTED: fake PHP spawned a sleep that inherited fd 9; see README (SIGKILL/SIGTERM caveat). ---'
