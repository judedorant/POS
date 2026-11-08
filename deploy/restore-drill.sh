#!/bin/sh
# Destructive only inside a brand-new isolated Docker network/database.
set -eu
if [ "$#" -ne 2 ]; then echo 'Usage: deploy/restore-drill.sh /absolute/backup.dump.age /absolute/age-identity' >&2; exit 2; fi
archive=$(realpath "$1")
identity=$(realpath "$2")
case "$archive" in *.dump.age) ;; *) echo 'Expected .dump.age file' >&2; exit 2;; esac
suffix="$(date +%s)-$$"
network="pos-restore-$suffix"
database="pos-restore-$suffix"
password=$(openssl rand -hex 32)
cleanup() { docker rm -f "$database" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; }
trap cleanup EXIT HUP INT TERM
docker network create --internal "$network" >/dev/null
docker run -d --name "$database" --network "$network" --tmpfs /var/lib/postgresql/data -e POSTGRES_USER=restore_admin -e POSTGRES_DB=pos_restore_test -e POSTGRES_PASSWORD="$password" postgres:17-bookworm >/dev/null
ready=0
for attempt in $(seq 1 30); do
    if docker exec "$database" pg_isready -U restore_admin -d pos_restore_test >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[ "$ready" = 1 ] || { echo 'Scratch database did not start' >&2; exit 1; }
docker run --rm --user "$(id -u):$(id -g)" --network "$network" --read-only --tmpfs /tmp:mode=1777 \
    -v "$archive:/run/backup.dump.age:ro" -v "$identity:/run/identity:ro" \
    -e PGHOST="$database" -e PGUSER=restore_admin -e PGDATABASE=pos_restore_test -e PGPASSWORD="$password" \
    --entrypoint sh tenant-pos-backup:release-001 -ec '
        age -d -i /run/identity -o /tmp/restore.dump /run/backup.dump.age
        pg_restore --exit-on-error --no-owner --no-acl -d pos_restore_test /tmp/restore.dump
        psql -v ON_ERROR_STOP=1 -c "SELECT count(*) AS restored_tenants FROM tenants" -c "SELECT count(*) AS restored_orders FROM orders" -c "SELECT count(*) AS restored_audit_events FROM audit_logs"
        psql -v ON_ERROR_STOP=1 -c "SET row_security=off; SELECT count(*) AS restored_stock_rows FROM inventory_stocks"
        psql -v ON_ERROR_STOP=1 -f /usr/local/share/verify-restore.sql
    '
echo 'Restore completed in isolated scratch database; scratch resources will now be removed.'
