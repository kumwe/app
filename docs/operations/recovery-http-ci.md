# Required App HTTP recovery evidence — PR #152

The recovery workflow runs the real App HTTP round trip on every pull request, merge-group build and
master push. `Required recovery acceptance` aggregates the App HTTP, three-engine native replay and
optional recovery-image tooling jobs. A failed, cancelled or skipped dependency fails that gate.
Parent repository settings own registering this stable check name in branch protection; adding a
workflow job does not itself change those settings. No manual acceptance checklist is involved.

`tests/Support/recovery-http-roundtrip.sh NEW_ABSOLUTE_REPORT_DIRECTORY` requires two distinct, empty,
explicitly disposable MariaDB databases. The workflow supplies fresh service databases and creates a
random database password in a private file. It invokes `mariadb` and `mariadb-dump` inside the exact
MariaDB 12.3.2 service image, retaining stdin for imports and using environment-based client credentials.
The existing admitted-native-runtime action and locked Composer dependencies boot the real App.

The harness copies code and installed dependencies into separate temporary source/restored App roots,
without copying dotenv or mutable application storage. It seeds the existing real API fixture, starts
loopback HTTP, proves CSRF login and a fresh mutation followed by replay, then stops the HTTP writer.
It signs a quiesced backup containing the receipt and idempotency ledger, restores to the second empty
database and payload roots, materializes the restored runtime, and starts a new HTTP process with a
separate Redis namespace. The original request, credentials, key and receipt must reproduce the original
status, body digest and ETag with `Idempotency-Replayed: true`. A media fixture and the receipt must
also compare byte for byte. Generic approval spent-state evidence remains in the existing App backup
acceptance lane; this HTTP fixture explicitly reports its business-record approval as not applicable.

Application secrets, bearer tokens, cookie jars, request/response bodies and raw logs never become CI
artifacts. The harness uses private directories and files, bounds individual stage execution and output
sizes, and removes both temporary Apps and private data on success, failure and timeout. The workflow
also removes its caller-owned database credentials on every outcome. Only four fixed-name JSON reports
are eligible for upload; the workflow rejects unexpected files and reports above 8 KiB and retains the
artifact for seven days. Failures identify the stage and exit status without publishing raw App logs.

The optional image job builds `recovery-mariadb` and `recovery-postgres` sequentially so their shared
PHP/native-engine/dependency layers are reused. It checks non-root execution, all deployed recovery
helper siblings, native command availability and PostgreSQL major 17. These are build/tooling checks;
database replay correctness is exercised by the separate native matrix, not inferred from an image build.

No ContainerFactory binding, production PHP API, frozen machine contract or migration changes are needed.
For local execution, provide `DB_*`, `REDIS_*`, `KUMWE_RECOVERY_FIXTURE_DISPOSABLE=yes`,
`KUMWE_RECOVERY_HTTP_RESTORE_DB`, and `KUMWE_RECOVERY_DB_PASSWORD_FILE`. Optional
`KUMWE_RECOVERY_APP_SOURCE` selects an installed source checkout to copy without modifying it. The
caller owns provisioning and removal of its two disposable databases. Run sandbox services and the
test in one invocation of `KUMWE_SETUP_BINLOG=1 ../kumwe-setup/with-services.sh`; never reuse an existing
App database to avoid a migration checksum or fixture collision.
