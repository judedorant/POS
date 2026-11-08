#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv app_password APP_DB_PASSWORD
\getenv migrator_password MIGRATOR_DB_PASSWORD
\getenv backup_password BACKUP_DB_PASSWORD
CREATE ROLE pos_migrator LOGIN BYPASSRLS PASSWORD :'migrator_password';
CREATE ROLE pos_app LOGIN NOBYPASSRLS PASSWORD :'app_password';
CREATE ROLE pos_backup LOGIN BYPASSRLS PASSWORD :'backup_password';
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO pos_migrator;
GRANT USAGE ON SCHEMA public TO pos_app, pos_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE pos_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pos_app;
ALTER DEFAULT PRIVILEGES FOR ROLE pos_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO pos_app;
ALTER DEFAULT PRIVILEGES FOR ROLE pos_migrator IN SCHEMA public GRANT SELECT ON TABLES TO pos_backup;
ALTER DEFAULT PRIVILEGES FOR ROLE pos_migrator IN SCHEMA public GRANT SELECT ON SEQUENCES TO pos_backup;
ALTER ROLE pos_app SET statement_timeout='15s';
ALTER ROLE pos_app SET lock_timeout='5s';
ALTER ROLE pos_app SET idle_in_transaction_session_timeout='30s';
SQL
# Reject plaintext bridge connections; local Unix socket remains available to bootstrap/admin.
printf '\nhostnossl all all all reject\n' > "$PGDATA/pg_hba.conf.tmp"
cat "$PGDATA/pg_hba.conf" >> "$PGDATA/pg_hba.conf.tmp"
mv "$PGDATA/pg_hba.conf.tmp" "$PGDATA/pg_hba.conf"
