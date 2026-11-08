#!/bin/sh
set -eu
umask 077
: "${AGE_RECIPIENT:?Public age recipient required}"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
# pg_dump must succeed before encrypting; no pipeline can conceal its failure.
pg_dump --format=custom --no-owner --no-acl --file="$work/database.dump"
age -r "$AGE_RECIPIENT" -o "$work/database.dump.age" "$work/database.dump"
name="pos-$stamp-$$.dump.age"
cp "$work/database.dump.age" "/backups/.$name.partial"
mv "/backups/.$name.partial" "/backups/$name"
cd /backups
sha256sum "$name" > "$name.sha256"
printf '%s\n' "$name"
