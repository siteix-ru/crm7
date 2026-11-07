#!/bin/sh
set -eu

[ "$(id -u)" -eq 0 ] || {
    echo "run as root" >&2
    exit 1
}

root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
updater="$root/../crm7_updater.py"

[ -f "$updater" ] || {
    echo "updater not found: $updater" >&2
    exit 1
}

install -d -m 0755 /opt/crm7-updater /etc/crm7-updater /var/log/crm7-updater
install -m 0644 "$updater" /opt/crm7-updater/crm7_updater.py
install -m 0755 "$root/crm7-update" /usr/local/bin/crm7-update
install -m 0644 "$root/crm7-update@.service" /etc/systemd/system/crm7-update@.service
install -m 0644 "$root/crm7-update-demo.timer" /etc/systemd/system/crm7-update-demo.timer
install -m 0644 "$root/crm7-update-k.timer" /etc/systemd/system/crm7-update-k.timer
install -m 0644 "$root/logrotate.conf" /etc/logrotate.d/crm7-updater

cat > /etc/crm7-updater/README <<'EOF'
Pause auto-update: touch /etc/crm7-updater/demo.disabled (or k.disabled)
Resume:            rm /etc/crm7-updater/demo.disabled
Logs:              /var/log/crm7-updater/
EOF

systemctl daemon-reload
systemctl enable --now crm7-update-demo.timer crm7-update-k.timer
systemctl list-timers --all --no-pager | grep crm7 || true
