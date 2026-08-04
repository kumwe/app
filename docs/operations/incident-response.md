# Incident response

## Preserve first

1. Record the time, deployed image digests, release, affected host and observed
   health responses.
2. Preserve centralized logs, reverse-proxy logs, PostgreSQL logs and relevant
   audit records under restricted access.
3. Snapshot persistent volumes or create a verified backup when doing so will not
   destroy evidence.
4. Do not run destructive cleanup, schema rollback or restore over the affected
   installation.

## Contain

- Close public traffic or writes at the reverse proxy.
- Revoke affected sessions, API credentials and external integrations through
  supported application controls.
- Rotate exposed Docker secrets and upstream credentials. Restart dependent
  services so rotated values take effect.
- If an extension is implicated, disable it through the audited extension
  lifecycle rather than deleting files in a running container.
- Preserve the compromised image and filesystem snapshot for analysis; replace
  service containers from a verified digest.

## Recover

Restore into an isolated environment, verify backup authenticity and integrity,
apply only supported 2.x migrations, and validate readiness plus application
smoke tests. Reopen traffic gradually and monitor authentication failures, audit
events, 5xx responses, job failures and database activity.

Document scope, root cause, dwell time, affected records, credential exposure,
corrective actions and release evidence. Follow applicable notification and data
protection requirements with qualified legal and security guidance.
