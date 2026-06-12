#!/bin/bash
# Apply Newt settings.
#
# Usage: apply.sh
#
# Called by action.php's "save" handler after it has written the newt.cfg.
# We reconcile the running daemon: start/restart it with the new environment.

CFG_FILE=/boot/config/plugins/newt/newt.cfg
LOCK_FILE=/var/run/newt-apply.lock
RESULT_FILE=/var/run/newt-apply-result.json
LOG_FILE=/var/log/newt.log
RC_SCRIPT=/etc/rc.d/rc.newt

# Record the outcome for the UI. $1 = true|false|null, $2 = message.
write_result() {
    printf '{"ok":%s,"ts":%s,"message":"%s"}\n' \
        "$1" "$(date +%s)" "$2" > "$RESULT_FILE" 2>/dev/null
}

# Serialize applies
exec 9>"$LOCK_FILE"
if ! flock -w 30 9 ; then
    echo "$(date '+%b %e %T') Unraid-Newt: apply.sh lock busy; aborting." >> "$LOG_FILE"
    write_result false "Another operation was in progress"
    exit 1
fi

write_result null "applying"

# Load config
if [ -f "$CFG_FILE" ]; then
    # shellcheck disable=SC1090
    . "$CFG_FILE"
fi

if [ "$ENABLE_NEWT" = "0" ] || [ "$ENABLE_NEWT" = "false" ] || [ -z "$ENABLE_NEWT" ]; then
    echo "$(date '+%b %e %T') Unraid-Newt: Disabling Newt; stopping daemon." >> "$LOG_FILE"
    "$RC_SCRIPT" stop >> "$LOG_FILE" 2>&1
    write_result true "Newt disabled; daemon stopped"
    exit 0
fi

# Ensure endpoint, ID, secret are configured
if [ -z "$NEWT_ID" ] || [ -z "$NEWT_SECRET" ] || [ -z "$PANGOLIN_ENDPOINT" ]; then
    echo "$(date '+%b %e %T') Unraid-Newt: Missing required configuration variables." >> "$LOG_FILE"
    write_result false "Endpoint, ID, and Secret must be configured"
    exit 1
fi

echo "$(date '+%b %e %T') Unraid-Newt: Applying settings (restarting daemon)..." >> "$LOG_FILE"

# Restart the service to apply new env vars
"$RC_SCRIPT" restart >> "$LOG_FILE" 2>&1
RC=$?

if [ "$RC" -ne 0 ]; then
    write_result false "Failed to start daemon (check syslog/logs)"
    exit 1
fi

# Wait for the interface to appear (signals successful tunnel handshake)
INTERFACE="${INTERFACE:-newt}"
TUNNEL_UP=false

echo "$(date '+%b %e %T') Unraid-Newt: Waiting for interface $INTERFACE to appear..." >> "$LOG_FILE"

for _ in $(seq 1 30); do
    if [ -d "/sys/class/net/$INTERFACE" ]; then
        TUNNEL_UP=true
        break
    fi
    sleep 0.5
done

if [ "$TUNNEL_UP" = "true" ]; then
    echo "$(date '+%b %e %T') Unraid-Newt: Interface $INTERFACE is up. Connected successfully!" >> "$LOG_FILE"
    write_result true "Connected"
else
    echo "$(date '+%b %e %T') Unraid-Newt: Timeout waiting for interface $INTERFACE. Connection might be retrying." >> "$LOG_FILE"
    write_result false "Failed to connect (check logs for credentials/network issues)"
    exit 1
fi
