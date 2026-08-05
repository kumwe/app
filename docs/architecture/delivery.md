# Delivery surfaces and authorization

## Shared use cases

Kumwe exposes application capabilities through four operator-facing surfaces:

| Surface | Primary users | Security boundary |
|---|---|---|
| Administrator | Editors and site administrators | Session, CSRF token, capability grants |
| CLI | Installers, operators, workers | Explicit `--site`, capability token file for management; protected host identity for bootstrap and process commands |
| REST | Applications and automation | Bearer token plus exact `Kumwe-Site`, scoped capabilities, ETags, idempotency keys |
| MCP | Approved AI clients and local tools | Bearer token plus exact site for HTTP or protected token file plus `--site` for stdio, protocol checks |

A core use case is complete only when every relevant surface either exposes it through the same application service or documents why that surface is intentionally read-only. Authorization is enforced in the application layer as well as at the route boundary; hiding a navigation link is not authorization.

## Capability model

Users receive roles (shown as groups in the administrator), and roles receive capability grants. Grants can be global or scoped to a component or content type. The built-in capability catalog covers administrator access, content lifecycle actions, navigation, users and roles, settings, extensions, and automation.

Every administrator route declares its required capability. Browser forms require a CSRF token. API tokens receive an explicit subset of the creating user's effective capabilities, are bound to one site and adapter purpose, and are stored only as digests. HTTP and CLI callers must repeat that exact site explicitly; host metadata is never an authority source. Workflow transitions require their action-specific capability, so review, publish, unpublish, archive, and restore may be assigned separately.

## Consistency and errors

- HTML mutations redirect after success and display a durable result from the application service.
- REST uses `application/problem+json`, optimistic ETags, and persisted idempotency results.
- CLI commands return non-zero on failure and must not print secrets after their one-time creation response.
- MCP handlers return structured protocol errors and must not bypass REST/application safety controls.
- Jobs carry actor or system identity in their audited context.

When adding a use case, update its application service, authorization capability, administrator route if end users manage it, CLI command if operators need it, REST/OpenAPI operation if integrations need it, and MCP tool/resource only when the AI interaction is safe and useful. Add parity tests that prove those paths reach the same rule set.
