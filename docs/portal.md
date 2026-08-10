# Ordinary-user portal

The portal is the authenticated ordinary-user surface at `/portal`. It is intentionally separate from the public
site, `/administrator`, recovery mode, REST bearer authentication, CLI, and MCP. It reuses the shared identity,
membership, capability, policy, approval, step-up, audit, and business-record application boundaries without
reusing an administrator session or administrator presentation service.

## Session and context boundary

Portal login accepts a shared human identity, then requires the server-side context resolver to choose a current
site membership and optional workspace. The optional workspace field is only a bounded hint matched against the
actor's live selections; it cannot establish an organization, role, policy generation, or authority claim.
Unknown, ambiguous, inactive, and stale selections receive the same non-enumerating response as invalid
credentials. Successful login creates an opaque portal-only cookie and server-side session.

The persisted session binds the digest of the cookie token to its own CSRF secret, site, organization, workspace,
membership identifier and version, policy generation, security epoch, user-agent digest, timestamps, and expiry.
Creation, step-up rotation, logout, expiry purge, and invalidation update resource-site ownership in the same
transaction. Membership, selected-membership roles, policy generation, user status, security epoch, workspace
assignment, and extension trust are revalidated on use. A portal session loads global grants plus roles from only
its exact selected membership; roles from another membership are never unioned into it.

Cookie requirements are:

- portal-only name and `Path=/portal`;
- `HttpOnly`, `SameSite=Strict`, and `Secure` in production;
- an exact maximum age matching server-side expiry;
- no acceptance at lookalike prefixes such as `/portal-example`.

There is no separate portal-lifetime setting in this increment. `APP_ADMIN_SESSION_SECONDS` supplies the portal
store lifetime and each portal login/rotation cookie `Max-Age` identically; the session tables, cookie names,
paths, middleware, and rotators remain separate. Changing that setting therefore requires testing both surfaces.

The administrator cookie is never read by portal middleware, and the portal cookie is never read by administrator,
public, API, CLI, MCP, worker, or recovery composition.

## Request pipeline

Routing and method resolution happen before authentication. The portal middleware then runs in this order:

1. portal session loading and current membership/security-epoch validation;
2. route capability authorization through the shared deny-by-default gateway;
3. bearer authentication for non-portal delivery paths;
4. dispatch or not-found.

`/portal/login` is the only portal-session exemption. It accepts only its declared GET and POST methods. Before a
session exists, GET and every error rotate a CSPRNG double-submit token in an HttpOnly, `SameSite=Strict`,
`Path=/portal/login` cookie and hidden form value; POST compares them in constant time before authentication and
success clears the token. The home, security, approval, and logout routes require `portal.access` through the
shared gateway against the exact owned `portal_session` item. Every authenticated state-changing portal form is
POST with a session-bound CSRF token; contributed mutation routes receive the same CSRF middleware.

Authentication failures do not disclose whether an email, membership, organization, or workspace exists. The
login path is rate limited through the shared authentication boundary. Logout deletes the persisted session and
resource ownership before expiring the cookie.

## Account security and approvals

The shell shows the server-selected site, organization, and workspace plus capability-filtered navigation. The
account-security page supports TOTP enrollment and confirmation, one-time recovery-code display, fresh TOTP or
recovery challenges, and logout of the current session. It refuses to overwrite an active TOTP credential, and
recovery codes are never retrievable after enrollment.

Approval pages use the scoped approval query boundary for an inbox and immutable request detail. Non-business
approval families continue through that generic workflow. A `business_record` request additionally remains
visible only while its active definition has `portal_exposure`, explicitly lists `approval` in
`portal_operations`, and still declares the bound high-impact action with `portal: true`. This is an exposure-only
ceiling: an approver needs approval-review authority, not the maker's action-execution capability. Malformed, stale,
unexposed, absent, and denied business bindings share the same omission/not-found behavior.

Portal approval templates receive a minimal projection without the internal resource ID or record key,
requester/approver IDs, role and rule evidence, or payload/binding digests. Approve, reject, and revoke controls
appear only when the projection permits them. Inside the decision transaction the server resolves the detail
again through the same approval-and-surface gate immediately before step-up and mutation, rotates the portal
session, and requires a fresh proof whose purpose is exactly `business.approval.approve`,
`business.approval.reject`, or `business.approval.revoke`. The portal never reads approval tables directly or
treats a remembered timestamp or UI state as approval or multi-factor evidence.

Published business definitions receive generated portal screens only when `portal_exposure` is true and the exact
operation appears in `portal_operations`. The allow-list defaults to empty; a list/view/action flag never implies
create, update, archive, relation, approval, report, export, or status access. Generated screens use the shared
application boundary and omit denied fields from controls, values, choices, counts, errors, and metadata. Essential
CRUD, confirmation, relation, action, and history paths remain native CSRF-protected forms without JavaScript. See
[Generated business surfaces](architecture/generated-business-surfaces.md).

## Trusted portal contributions

Core and signed extensions register workspaces, templates, navigation items, and routes into the single
owner-aware extension contribution registry set. A contribution is accepted only after its capability and
resource policy are live and its owner, signature, checksum, path, handler factory, template reference, and route
shape pass validation.

Contributed routes are mounted only below `/portal/extensions/{vendor}/{name}`. The registry rejects reserved core
paths, duplicate names, unsupported methods, missing capabilities, untrusted handlers, arbitrary callables, and
templates outside a realpath-contained active runtime root. Mutation routes automatically receive CSRF
protection. Every handler is wrapped with a live trust check, so disable, uninstall, replacement, or trust
revocation removes access immediately without restarting a process.

Portal templates receive a constrained presentation model and renderer rather than the container, connection,
filesystem, identity stores, or authorization gateway. Navigation is capability filtered on the server. Hiding a
link is usability only; route and application authorization remain mandatory.

## Accessible presentation

The built-in shell provides a skip link, semantic landmarks, visible focus, keyboard navigation, labelled forms,
status and error regions, responsive layout, and contrast-compatible colors. Security controls remain usable
without JavaScript. Plaintext credentials and recovery codes use `no-store` responses and are disclosed only at
the action that creates them.

## Operations and attacks to test

Monitor portal login/challenge throttles, session creation and invalidation, membership freshness failures,
capability denials, CSRF failures, approval state changes, contributed-route trust failures, and unexpected owner
lifecycle transitions. Logs must not contain passwords, cookie or CSRF plaintext, TOTP secrets/codes, recovery
codes, or restricted business fields.

The Playwright portal specification uses the normal browser fixture and runs on desktop and mobile Chromium. It
checks accessible login and shell markup, responsive layout, visible context/navigation, pre-authentication CSRF,
portal cookie name and path isolation, rejection of the ordinary portal identity at administrator login,
missing-CSRF rejection, logout, and post-logout session invalidation. Run it with `npm run test:browser`;
screenshots remain test artifacts rather than source files.

Unit, integration, architecture, and release gates additionally cover current-membership role isolation, stale
membership/policy/epoch rejection, capability denial, non-enumerating login/detail behavior, TOTP and recovery
replay, approval replay, malicious contribution paths, missing portal-session policies, untrusted templates,
owner disable/uninstall, and recovery composition. Recovery composition must not resolve a portal renderer, route
provider, extension runtime, or ordinary session store while recovery mode is active.

See [business security](business-security.md) for the common authorization, field, approval, step-up, token, and
extension model.
