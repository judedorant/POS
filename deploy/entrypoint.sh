#!/bin/sh
set -eu
umask 077
mkdir -p /tmp/storage/framework/views /tmp/storage/framework/cache/data /tmp/storage/framework/sessions /tmp/storage/logs
php artisan config:cache >/dev/null
exec "$@"
