#!/usr/bin/env bash
set -Eeuo pipefail
cd /app
for attempt in {1..60}; do
    if timeout 2 valkey-cli ping 2>/dev/null | grep -qx PONG; then
        exec "$@"
    fi
    sleep 1
done
echo 'Valkey is unavailable; refusing to start the dependent service.' >&2
exit 1
