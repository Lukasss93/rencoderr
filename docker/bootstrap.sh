#!/usr/bin/env bash
set -Eeuo pipefail
trap 'touch /run/rencoderr/failed' ERR
cd /app
rm -f /run/rencoderr/ready

for attempt in {1..60}; do
    if timeout 2 valkey-cli ping 2>/dev/null | grep -qx PONG; then
        break
    fi
    if [[ $attempt == 60 ]]; then
        echo 'Valkey did not become ready.' >&2
        exit 1
    fi
    sleep 1
done

artisan() { runuser -u rencoderr -- php artisan "$@" --no-interaction; }
artisan config:cache
artisan migrate --force
artisan route:cache
artisan view:clear
artisan view:cache
artisan schedule:clear-cache
artisan cache:forget encoding.detection
artisan tasks:recover
# Start dependents only after all bootstrap operations succeed.
supervisorctl -c /opt/rencoderr/supervisord.conf start horizon octane scheduler
touch /run/rencoderr/ready
