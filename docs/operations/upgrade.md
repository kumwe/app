# Upgrade Kumwe

Kumwe releases use forward-only, advisory-locked database migrations. Upgrade
from a healthy supported release and verify extension compatibility before
changing the running images.

## Procedure

1. Verify the target release, signatures, checksums, SBOM and provenance as
   described in [release verification](release-verification.md).
2. Read the release notes and verify PHP, PostgreSQL, Redis and extension
   compatibility.
3. Quiesce writes at the reverse proxy or application maintenance boundary.
4. Create and independently verify a backup using the exact current release.
5. Pull the target image digests without replacing running containers.
6. Run the migration task once. It holds the database migration lock.
7. Start the target `app`, confirm readiness, then replace `web` and automation
   processes.
8. Exercise login, content reads, a reversible draft write, media access and the
   API contract before reopening writes.
9. Record deployed image digests and retain the pre-upgrade backup according to
   policy.

Typical Compose commands after the backup is verified:

```bash
docker compose -f compose.production.yaml pull
docker compose -f compose.production.yaml run --rm migrate
docker compose -f compose.production.yaml --profile automation up -d --no-deps app web worker scheduler
curl --fail --silent http://127.0.0.1:8080/health/ready
```

Schema migrations are forward-only. Do not attempt to run down-migrations after
a failed release. Keep writes closed, preserve logs, deploy a compatible fixed
image when the schema is healthy, or restore the verified backup into an empty
database, media directory, and extensions directory.
