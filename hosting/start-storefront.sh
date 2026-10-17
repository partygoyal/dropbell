#!/bin/bash
set -eu
cd /home/u310490635/domains/dropbell.in
exec 9>storefront.lock
flock -n 9 || exit 0
export NODE_ENV=production
export LARAVEL_API_URL=https://dropbell.in/backend-api
child_pid=
trap 'if [ -n "$child_pid" ]; then kill -TERM "$child_pid" 2>/dev/null || true; fi; exit 0' TERM INT
while true; do
    /opt/alt/alt-nodejs22/root/usr/bin/node storefront/socket-server.js >> storefront.log 2>&1 &
    child_pid=$!
    wait "$child_pid" || true
    child_pid=
    sleep 5
done
