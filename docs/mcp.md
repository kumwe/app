# MCP integration

Kumwe exposes capability-protected CMS application services through the official PHP MCP SDK. The same server runs over authenticated Streamable HTTP and local stdio. MCP handlers call Kumwe application services; they do not query tables, edit files, or bypass workflow, authorization, revisions, optimistic versions, or audit records.

## Streamable HTTP

The endpoint is `https://cms.example.org/mcp`. Send a Kumwe bearer token on every request:

```http
Authorization: Bearer TOKEN
Kumwe-Site: corporate
```

The required `Kumwe-Site` header selects exactly one validated site and must match the site bound into the token. Host and forwarding headers never select the site. The transport also validates the exact host, Origin/CORS behavior, MCP protocol version, request body limit, and persistent session. The route requires authentication; each tool then requires its own capability. A settings-only token can use settings tools without also receiving content access.

## Local stdio

Create a short-lived, least-privilege token and store it in an absolute owner-readable file. Configure the client to launch:

```bash
php bin/kumwe mcp:serve \
  --site=corporate \
  --token-file=/run/secrets/kumwe-mcp-token
```

The required `--site` value selects exactly one site for the lifetime of the stdio process. The token must have been issued for that same site; a token for `default` or another site is rejected. Stdio does not grant implicit local administrator power. The token is reverified for expiry/revocation and its current capabilities before each protected handler access. Run the process as a dedicated unprivileged service account; never give an MCP client database-administration credentials, signing private keys, backup keys, or unrestricted extension/media filesystem access.

## Tools

| Tool | Capability | Behavior |
|---|---|---|
| `kumwe_discover` | Authenticated | List the exposed tools, resources, and prompts |
| `kumwe_content_list` | `content.read` | List content, optionally including trashed records |
| `kumwe_content_create` | `content.create` | Create a draft page |
| `kumwe_content_update` | `content.update` | Update title, slug, and body at an expected version |
| `kumwe_content_transition` | Transition-specific | Submit, review, publish, unpublish, archive, or restore through workflow policy |
| `kumwe_menu_list` | `navigation.manage` | List menus |
| `kumwe_menu_create` | `navigation.manage` | Create a menu |
| `kumwe_menu_item_list`, `kumwe_menu_item_get` | `navigation.manage` | Read typed, nested menu items |
| `kumwe_menu_item_create`, `kumwe_menu_item_update` | `navigation.manage` | Link content, sections, or safe URLs and maintain menu paths |
| `kumwe_menu_item_delete` | `navigation.manage` | Delete a versioned menu item |
| `kumwe_settings_get` | `settings.manage` | Read browser-managed site settings |
| `kumwe_settings_update` | `settings.manage` | Update validated identity, homepage, primary menu, schemes, and interaction styles |
| `kumwe_user_list` | `users.manage` | List users, groups, and assignments |
| `kumwe_token_list`, `kumwe_token_revoke` | `users.manage` | Inspect token metadata or revoke access |
| `kumwe_extension_list` | `extensions.manage` | List installed extensions and state |
| `kumwe_extension_activate` | `extensions.manage` | Activate an installed extension and runtime map |
| `kumwe_schedule_list` | `automation.manage` | List schedules |
| `kumwe_schedule_create` | `automation.manage` | Create a validated recurring schedule |
| `kumwe_business_definition_list`, `kumwe_business_definition_get` | `content.read` | List the definition catalogue or read a published version |
| `kumwe_business_definition_draft`, `kumwe_business_definition_history` | `content.read` | Read the working draft or the published version history |
| `kumwe_business_definition_compatibility` | `content.read` | Preview the compatibility plan the draft would publish |
| `kumwe_business_definition_publish` | `content.update` | Publish the draft as a new immutable version at an expected revision |
| `kumwe_business_schema_definitions`, `kumwe_business_schema_plan_list` | `business.schema.read` | List plannable definitions or existing plans |
| `kumwe_business_schema_plan_get` | `business.schema.read` | Read a plan with its durable step journal and canonical checksum |
| `kumwe_business_schema_plan_create` | `business.schema.plan` | Compile a deterministic plan; runs no DDL |
| `kumwe_business_schema_plan_approve` | `business.schema.approve` | Approve the exact inspected plan checksum |
| `kumwe_business_schema_plan_execute` | `business.schema.execute` | Apply an approved plan under the schema lock |
| `kumwe_business_schema_plan_recover` | `business.schema.recover` | Resume or reconcile an interrupted plan |

Each schema stage names only its own capability, so a token granted inspection cannot approve, and one granted approval cannot execute. `execute` and `recover` are annotated destructive because they change physical tables. Approval binds to the plan checksum read from `kumwe_business_schema_plan_get`: a plan that changed after inspection is refused rather than applied.

Two schema operations are deliberately absent. Composing a destructive purge plan, and approving a high-impact plan, both require re-proving the caller's current password, which this surface cannot supply; publishing them would only produce tools that always fail closed. Use the administrator screen or the protected CLI for those.

High-risk operations that would transmit a password, install an arbitrary package, delete state, or grant permissions are intentionally not MCP tools. Use the administrator, protected CLI, or REST endpoint with the operation's explicit safeguards.

## Generated business tools

Generated records use a bounded static vocabulary; Kumwe never registers a tool for every entity. The current
actor's catalog determines which definitions, fields, views, actions, and relations appear.

| Tool | Behavior |
|---|---|
| `kumwe_business_discover` | List policy-visible generated entities and capabilities |
| `kumwe_business_inspect` | Inspect one policy-visible schema, view, action, and relation set |
| `kumwe_business_search` | Execute the shared bounded filter/search/sort/projection document |
| `kumwe_business_read` | Read one record by public identity and optional lifecycle flags |
| `kumwe_business_history` | Read up to 200 policy-filtered revisions before an optional version cursor |
| `kumwe_business_plan_mutation` | Seal a proposed mutation to current actor, scope, policy, definition/runtime generation, input digest, and record version |
| `kumwe_business_create`, `kumwe_business_update` | Create or version-update through the transactional record service |
| `kumwe_business_archive`, `kumwe_business_restore`, `kumwe_business_delete` | Apply lifecycle mutation at an expected version |
| `kumwe_business_relate`, `kumwe_business_unrelate`, `kumwe_business_reorder` | Change a declared relation or ordered line at an expected source version |
| `kumwe_business_request_action` | Request maker-checker approval for an exact action binding |
| `kumwe_business_execute_action` | Execute the action, consuming the exact approved binding when required |
| `kumwe_business_operation_status` | Read a completed operation only under its original actor/scope/policy bindings |

Schemas are closed and bounded, and tool annotations distinguish read-only, destructive, and idempotent behavior.
Every write requires a 16–128 character `operationId` and the opaque five-minute token returned by
`kumwe_business_plan_mutation` for the exact same arguments. Plan execution re-resolves every binding and fails
closed if the policy generation, trusted runtime, definition checksum/version, actor/scope, authorization context,
approval-request identity, record version, action, relation, or canonical payload changed. Results use the same
omission-safe projector as REST/CLI/UI and never include record keys, actor IDs, policy evidence, or denied handles.

Approval voting is intentionally absent. A bearer MCP context has no fresh single-use step-up proof, and Kumwe does
not accept a password or authenticator code through a model tool. Request approval through MCP, decide it through
the administrator or portal step-up flow for inspection, but do not treat that as an MCP execution grant: the
predecessor binding fingerprints the requesting surface and the proof store accepts only browser sessions. An MCP
attempt to consume a high-impact approval therefore fails closed. Complete high-impact execution in the browser;
ordinary planned actions remain fully executable through MCP. See [Generated business
surfaces](architecture/generated-business-surfaces.md).

## Safe mutations

Every MCP mutation requires an `operationId` containing 16–128 safe characters. Generate one stable ID per intended change and keep it for retries. Kumwe stores its request digest and completed result for 24 hours:

- retrying the same operation and input returns the stored result;
- reusing the ID for different input is rejected;
- retrying while the first operation is running reports an in-progress conflict;
- a failed operation releases the ID so a corrected retry can proceed.

Updates and transitions also require the current positive `version`. A stale version fails instead of overwriting another editor or integration. Workflow transitions perform action-specific authorization even though one tool accepts several target states.

Publishing, unpublishing, extension activation, settings changes, and schedules are audited with the bearer-token subject. Give an AI client only the capabilities required for the immediate task and rotate/revoke its token when the task ends.

## Resource and prompt

`kumwe://capabilities` returns the machine-readable server surface. The `kumwe_site_review` prompt prepares a content, SEO, structure, or extension review using authorized tools. The prompt does not grant capabilities and cannot expand the token's access.

## Client policy

- Require human confirmation in the MCP client before publishing, changing settings, or activating extensions.
- Prefer read-only tokens for analysis and separate short-lived tokens for approved writes.
- Do not place tokens, passwords, personal data, or unpublished bodies in client logs or model-training stores.
- Keep operation IDs and returned versions in the calling workflow so retries remain safe.
- Monitor audit events, authentication failures, denied capabilities, and unusual mutation volume.

MCP tokens use the `kumwe-mcp` audience and MCP purpose and remain bound to their live site, organization,
workspace, membership version, policy generation, security epoch, token family, and delegation depth. Tool,
resource, and prompt discovery does not expand that authority. Any business-record resource or tool must pass the
shared row and field disclosure plan before returning identities, counts, relations, aggregates, or values.

Use [REST](rest-api.md) for the complete machine-to-machine API and [Architecture: delivery](architecture/delivery.md) for the shared-service contract.
