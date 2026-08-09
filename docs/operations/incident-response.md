# Incident response

## Preserve first

1. Record the time, deployed image digests, release, affected host and observed
   health responses.
2. Preserve centralized logs, reverse-proxy logs, database logs and relevant
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
- Suspend affected organization/workspace memberships, increment the subject security epoch, and revoke portal
  and administrator sessions plus all API/CLI/MCP token families. Do not wait for credential expiry.
- Disable the affected contribution owner so its capabilities, resource policies, portal routes, navigation, and
  templates leave the live registries together.
- Revoke pending approvals whose requester authority, resource version, payload, policy, or approver set may have
  been affected. A remembered step-up timestamp is not evidence; nonce proofs are purpose/session bound.
- Preserve the compromised image and filesystem snapshot for analysis; replace
  service containers from a verified digest.

## Recover

Restore into an isolated environment, verify backup authenticity and integrity,
apply only supported 2.x migrations, and validate readiness plus application
smoke tests. Reopen traffic gradually and monitor authentication failures, audit
events, 5xx responses, job failures and database activity.

Re-enroll TOTP credentials if the encryption key or enrollment secret may have been exposed; invalidate every
recovery code and session associated with the old credential. Rebuild memberships and security policies through
typed administrator forms, then verify effective access with a limited account in every affected organization and
workspace.

Document scope, root cause, dwell time, affected records, credential exposure,
corrective actions and release evidence. Follow applicable notification and data
protection requirements with qualified legal and security guidance.
