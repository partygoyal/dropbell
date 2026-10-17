#!/bin/bash
set -eu
cd /home/u310490635/domains/dropbell.in
exec 9>storefront.lock
flock -n 9 || exit 0
export NODE_ENV=production
export LARAVEL_API_URL=https://dropbell.in/backend-api
exec /opt/alt/alt-nodejs22/root/usr/bin/node storefront/socket-server.js >> storefront.log 2>&1
