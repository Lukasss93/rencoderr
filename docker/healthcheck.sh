#!/usr/bin/env bash
set -Eeuo pipefail
[[ -f /run/rencoderr/ready && ! -f /run/rencoderr/failed ]]
for service in valkey horizon octane scheduler; do
    supervisorctl -c /opt/rencoderr/supervisord.conf status "$service" | grep -q ' RUNNING '
done
timeout 2 valkey-cli ping | grep -qx PONG
php -r '$db = new PDO("sqlite:".getenv("DB_DATABASE")); $db->query("SELECT 1 FROM migrations LIMIT 1");'
curl --fail --silent --max-time 3 http://127.0.0.1:8000/up >/dev/null
