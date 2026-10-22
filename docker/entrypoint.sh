#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $(id -u) != 0 ]]; then
    echo 'Start the container as root and configure PUID/PGID for application processes.' >&2
    exit 1
fi
for name in PUID PGID OCTANE_WORKERS HORIZON_WORKERS; do
    value=${!name}
    if [[ ! $value =~ ^[1-9][0-9]*$ ]]; then
        echo "$name must be a positive integer." >&2
        exit 1
    fi
done

mkdir -p /config
exec 9>/config/.container.lock
if ! flock -n 9; then
    echo 'Another container is already using this /config directory.' >&2
    exit 1
fi

if ! getent group "$PGID" >/dev/null; then
    groupmod --gid "$PGID" rencoderr
fi
usermod --non-unique --uid "$PUID" --gid "$PGID" rencoderr
for device in /dev/dri/* /dev/nvidia*; do
    [[ -c $device ]] || continue
    device_gid=$(stat -c '%g' "$device")
    [[ $device_gid != 0 ]] || continue
    if ! getent group "$device_gid" >/dev/null; then
        groupadd --gid "$device_gid" "device-$device_gid"
    fi
    usermod --append --groups "$device_gid" rencoderr
done

mkdir -p /config/database /config/valkey /config/storage/{app/private,app/public,framework/cache/data,framework/cache/locks,framework/sessions,framework/views,logs} /run/rencoderr /app/bootstrap/cache
rm -f /run/rencoderr/ready /run/rencoderr/failed /run/rencoderr/octane.json /app/bootstrap/cache/config.php /app/bootstrap/cache/routes-v7.php
rm -f /run/rencoderr/application.log
mkfifo /run/rencoderr/application.log
php /opt/rencoderr/initialize-key.php /config/app-key
export APP_KEY
APP_KEY=$(cat /config/app-key)
touch /config/database/database.sqlite
chown -R "$PUID:$PGID" /config/database /config/valkey /config/storage /run/rencoderr /app/bootstrap/cache
chown "$PUID:$PGID" /config/app-key
chmod 600 /config/app-key

exec /usr/bin/python3 /opt/rencoderr/launcher.py
