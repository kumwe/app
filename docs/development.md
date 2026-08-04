# Development and testing

Kumwe supports PHP 8.4 and 8.5 with PostgreSQL 17. The development image includes Composer and the required PHP extensions.

```bash
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app composer qa
```

Individual checks:

```bash
composer architecture:policy
composer cs
composer analyse
composer test:unit
composer test:integration
composer security:audit
```

Add unit tests for every Kumwe-owned class with meaningful behavior. Persistence adapters, migrations, HTTP composition, extension activation, and concurrency require PostgreSQL integration or functional tests. Test extension archives with traversal, links, duplicate paths, expansion limits, invalid manifests, compatibility errors, unknown signing keys, and bad signatures.

Before opening a change:

1. Run `composer qa` against a clean PostgreSQL database.
2. Build both production targets with `docker build --target runtime` and `--target web`.
3. Run filesystem and image vulnerability scans at the repository's configured severity threshold.
4. Exercise `tools/backup.sh`, `tools/restore-verify.sh`, and `tools/restore.sh` against disposable targets.
5. Confirm `/health/live`, `/health/ready`, administrator login, a draft write, a workflow transition, and an idempotent API retry.

GitHub Actions executes this matrix for pull requests and release tags. Release tags use `v2.x.y` and publish signed checksums, image digests, provenance, and CycloneDX SBOMs.
