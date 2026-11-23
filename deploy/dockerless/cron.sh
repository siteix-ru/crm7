#!/bin/sh
# CRM7 — Dockerless VPS cron wrapper.
#
# Runs api/bin/cron.php under a non-blocking flock so two cron invocations can
# never send the same email queue batch twice. All paths are outside the web
# root; no files are created in /run, /tmp or the public directory.
#
# Override via environment (all values are quoted, no shell interpolation):
#   CRM7_ROOT         installation root (default /srv/crm7)
#   CRM7_PHP_BIN      PHP CLI binary   (default /usr/bin/php)
#   CRM7_FLOCK_BIN    flock binary     (default flock, resolved via PATH)
#   CRM7_CRON_LOCK    lock file        (default $CRM7_ROOT/var/crm7-cron.lock)
#   CRM7_CRON_LOG     log file         (default $CRM7_ROOT/var/log/cron.log)
#
# Exit codes:
#   0                PHP finished successfully (PHP exit code is passed through)
#   75 (EX_TEMPFAIL) another cron run already holds the lock; PHP was NOT started
#   69 (EX_UNAVAILABLE) missing flock/PHP/workdir/cron.php or unreadable dir
#   73 (EX_CANTCREAT) cannot open/create the lock file
#   74 (EX_IOERR)     cannot open/append the log file
#   other            exit code returned by api/bin/cron.php

set -u
umask 077

CRM7_ROOT=${CRM7_ROOT:-/srv/crm7}
CRM7_PHP_BIN=${CRM7_PHP_BIN:-/usr/bin/php}
CRM7_FLOCK_BIN=${CRM7_FLOCK_BIN:-flock}
CRM7_CRON_LOCK=${CRM7_CRON_LOCK:-$CRM7_ROOT/var/crm7-cron.lock}
CRM7_CRON_LOG=${CRM7_CRON_LOG:-$CRM7_ROOT/var/log/cron.log}

WORKDIR=$CRM7_ROOT/api
CRON_SCRIPT=$WORKDIR/bin/cron.php

fail() {
    printf '%s\n' "crm7-cron: $2" >&2
    exit "$1"
}

command -v "$CRM7_FLOCK_BIN" >/dev/null 2>&1 || fail 69 "flock not found: $CRM7_FLOCK_BIN"
[ -x "$CRM7_PHP_BIN" ] || fail 69 "PHP CLI not executable: $CRM7_PHP_BIN"
[ -d "$WORKDIR" ] || fail 69 "missing workdir: $WORKDIR"
[ -f "$CRON_SCRIPT" ] || fail 69 "missing cron script: $CRON_SCRIPT"

lock_dir=${CRM7_CRON_LOCK%/*}
log_dir=${CRM7_CRON_LOG%/*}
[ -d "$lock_dir" ] || fail 69 "missing lock directory: $lock_dir"
[ -d "$log_dir" ] || fail 69 "missing log directory: $log_dir"

# Verify writability without truncating existing files.
( : >>"$CRM7_CRON_LOCK" ) 2>/dev/null || fail 73 "cannot open lock file: $CRM7_CRON_LOCK"
( : >>"$CRM7_CRON_LOG" ) 2>/dev/null || fail 74 "cannot open log file: $CRM7_CRON_LOG"

# Keep fd 9 open: flock is released by the kernel when the process exits,
# including abnormal termination. The lock file itself is never removed.
exec 9>>"$CRM7_CRON_LOCK" || fail 73 "cannot open lock file descriptor: $CRM7_CRON_LOCK"
"$CRM7_FLOCK_BIN" -n 9 || fail 75 "another cron run is already active"

cd "$WORKDIR" || fail 69 "cannot change directory: $WORKDIR"

# exec preserves the PHP exit code and keeps fd 9 (hence the lock) until exit.
exec "$CRM7_PHP_BIN" "$CRON_SCRIPT" >>"$CRM7_CRON_LOG" 2>&1
