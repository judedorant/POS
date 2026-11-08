# Phase 9 — Docker Compose on a VPS

This is a single-host deployment, not high availability. A VPS outage stops checkout synchronization until recovery; offline cash sales remain available on already provisioned tills. Stripe remains **test mode only**. No public deployment or real provider payment has been performed by these files.

## Runtime and boundaries

Caddy terminates HTTPS and serves the compiled SPA. PHP-FPM, Reverb, payment and realtime workers, and one scheduler use the same immutable application image. Only Caddy exposes ports. PostgreSQL stores business data, sessions (encrypted), shared cache/locks, and durable queues. Redis is deliberately unnecessary for this deployment size. Reverb scaling stays disabled. Container-local writable data is temporary; no receipt history depends on it.

Database connections verify the private CA and the `db` hostname. `pos_app` has DML access and cannot bypass RLS or own tables. `pos_migrator` owns application objects and bypasses RLS for historical migrations; only the tools-profile migration service receives its password. `pos_backup` bypasses RLS with read-only grants so backups include all tenants. Database administrator credentials are supplied only to PostgreSQL. These roles are security boundaries against application mistakes, not a defense against root on the VPS. Docker administrators can read container environment variables. Protect root access and the state directory.

## First deployment

Requirements: Linux VPS, Docker Engine + Compose v2, Python 3, OpenSSL, working DNS, at least 2 CPU / 4 GB RAM to begin, and sufficient disk for the database and encrypted backup retention. Tune FPM's 12-child limit using measured memory. Allow inbound 80/443 and restrict SSH to administrators; verify the host/provider firewall with Docker's published-port behavior. Do not publish PostgreSQL, FPM or Reverb ports.

1. Generate an age identity **on an administrator machine**, using `age-keygen -o pos-backup-identity.txt`. Store the identity offline/in a password vault; copy only its public `age1…` recipient to the VPS.
2. Copy the source and run `sudo python3 deploy/prepare.py pos.example.com age1PUBLIC_RECIPIENT`. It refuses to overwrite existing state. This writes unique secrets and a one-year database server certificate. Set a renewal alert for 30 days before expiry. Rotate the server certificate using the existing CA, preserve hostname `db`, set key owner 999 and mode 0400, then restart PostgreSQL. Keep the CA signing key outside the application containers.
3. Edit `deploy/state/runtime.env` as root. Add Stripe **test** secret and webhook secret through your secure editor/secret manager; never send them in chat or commit them. Leave them blank to use cash only. The test webhook endpoint is documented in the main README. Set `STRIPE_RETURN_URL` to this HTTPS origin. Ensure the runtime app password matches the infrastructure app password.
4. Build immutable release tags with the **public** Reverb key from runtime.env. Vite variables are public browser configuration; never pass a Stripe secret or Reverb secret as a build argument.

```sh
docker build -f deploy/Dockerfile --target runtime \
  --build-arg VITE_REVERB_APP_KEY=PUBLIC_KEY \
  --build-arg VITE_REVERB_HOST=pos.example.com -t tenant-pos:release-001 .
docker build -f deploy/Dockerfile --target web \
  --build-arg VITE_REVERB_APP_KEY=PUBLIC_KEY \
  --build-arg VITE_REVERB_HOST=pos.example.com -t tenant-pos-web:release-001 .
docker build -f deploy/backup.Dockerfile -t tenant-pos-backup:release-001 .
```

For managed cloud builds, add `--secret id=proxy_ca,src="$CODEX_PROXY_CERT"` to each build. This build-only certificate is removed before the image layer is committed. Normal VPS builds need no such secret. Pin reviewed base images by digest in your release process; tags alone can change between builds. Record resulting image digests and preserve the previous release images.

Use this shell function from the repository root (run as root when state is root-owned):

```sh
poscompose() {
  docker compose --env-file deploy/state/infrastructure.env \
    -f deploy/compose.production.yaml "$@"
}
poscompose config --quiet
poscompose up -d db
poscompose run --rm migrate
poscompose run --rm app php artisan pos:production-check
poscompose up -d
poscompose exec app php artisan pos:create-user owner@example.com 'Business Owner'
```

Keep the state environment files mode 0600. Never save `docker compose config` output containing resolved secrets. Initialization roles run only on a **fresh** PostgreSQL volume; changing an env password later does not alter a PostgreSQL role. Rotate through reviewed SQL and update the matching env values, then recreate affected services.

Verify `https://pos.example.com/up` and `/readyz`, login, create a tenant, receive stock, complete a cash sale, observe a second till, perform a refund, and test offline recovery. Register the Stripe test webhook, complete a real Stripe test Checkout session, and verify duplicate delivery and refund before claiming provider integration verified. Do not enable live Stripe keys: the application intentionally rejects them.

## Monitoring and logs

`/up` is process liveness; `/readyz` checks database and cache and returns a sanitized 503 on failure. Caddy applies no-store to dynamic responses and immutable caching to hashed build assets. PHP errors go to stderr with request correlation IDs; retain Docker logs with rotation, restricted access, and an external log destination. Set daemon/service log rotation (for example json-file max-size 10m / max-file 5) before production.

After two minutes, run `poscompose exec -T app php artisan pos:ops-check`. It exits nonzero if scheduler/payment/realtime heartbeat is absent or older than 180 seconds, ready jobs wait more than 180 seconds, or failed jobs exist. Have host monitoring run this every minute and alert on nonzero; it is not a public endpoint. Monitor external HTTPS availability, TLS renewal, CPU/RAM/disk, PostgreSQL connection count and backup freshness independently. Docker marks failed web health checks unhealthy but **does not restart an unhealthy process automatically**; process exits are restarted. Alert and investigate dependency failure instead of a restart loop.

Use `poscompose logs --tail=100 app payments realtime scheduler` and `php artisan queue:failed` to investigate. Do not log credentials, raw webhook payloads or payment/customer data. Review failures before a targeted `queue:retry UUID`; provider requests already use idempotency keys. A stale heartbeat is a symptom, not proof the provider failed. Payments with an uncertain provider creation result require operator reconciliation as described in the main README.

## Backups and disaster recovery

Run `poscompose run --rm backup` from a root-owned daily host timer, with a lock (`flock`) to prevent overlapping runs. It creates a transactionally consistent custom-format dump, encrypts it to the age public recipient, then atomically publishes the encrypted file and a SHA-256 sidecar. Plaintext exists only in the container's tmpfs and disappears on exit. Provision tmpfs memory for the uncompressed dump size; large databases need an encrypted staging disk or a streaming implementation with explicit subprocess failure handling. Backup errors must alert. Checksums detect accidental corruption; they do not authenticate who produced a backup.

Copy encrypted dumps and sidecars to an **off-host** destination after every successful backup; verify transfer before deleting local files. Suggested retention: 7 daily / 4 weekly / 12 monthly, adjusted to business requirements. No remote credentials or destructive pruning are embedded in this repository. An on-VPS backup alone does not survive loss of the VPS. Daily backups imply up to 24 hours of data loss; use managed PostgreSQL or WAL archiving/PITR if that RPO is unacceptable. Measure restore time for your RTO.

Preserve APP_KEY, runtime/infrastructure secrets, CA and release digests separately in an encrypted administrator vault. Restoring the database without APP_KEY loses encrypted sessions and any future encrypted application fields. The database dump contains sessions, queued payloads and all tenants; protect it as business data. Do not store the age private identity on the production application host.

On a separate recovery machine with Docker, the backup image, and the private identity:

```sh
sha256sum -c pos-TIMESTAMP.dump.age.sha256
./deploy/restore-drill.sh /absolute/pos-TIMESTAMP.dump.age /absolute/pos-backup-identity.txt
```

The drill creates a new internal-only network and ephemeral PostgreSQL database, decrypts into container tmpfs, restores with `--exit-on-error`, checks table counts and financial/stock invariants, and removes scratch resources. It has no production connection settings. Record expected counts at backup time and compare reported counts; a successful empty restore is not evidence of complete data. Run at least monthly and after schema changes. For a real recovery: isolate a new host, restore to a fresh database as migrator/admin, reapply runtime and backup grants, verify RLS and triggers, run preflight and business checks, then switch DNS. Pause workers until queued provider operations have been reconciled against Stripe test state; never replay cash dispensing as a physical action.

## Updates and rollback

Run CI, build both new image tags with identical public configuration, scan images/dependencies, and test on staging. Back up and verify off-host copy first. For this single VPS, schedule a short maintenance window: stop web, scheduler and workers, then run forward-only migrations with the new image. Update both image tags in infrastructure.env, run preflight, and start services. Never run `migrate:fresh`, delete volumes, or automatically roll back financial migrations in production.

If a release fails and its schema is backward compatible, restore the previous application **and web** image tags and recreate services. If incompatible, repair forward or restore to a separate recovery database under an explicit incident plan; restoring a backup loses newer committed sales and needs reconciliation. Preserve evidence and the failed database. Rehearse this procedure on staging. `docker compose down -v` destroys persisted data and must not be used for routine updates.

For a bounded read-only staging baseline, run `POS_LOAD_URL=https://staging.example.com/readyz node scripts/load-smoke.mjs`. Defaults are 100 requests / 5 concurrent, capped at 1000 / 20. It reports failures and p50/p95 latency. This checks HTTP/dependency capacity only; it is not a checkout throughput claim. Use the existing sale/refund concurrency tests for correctness, and model authenticated multi-tenant checkout workloads on disposable staging data before choosing capacity targets.
