# MCP integration

Kumwe exposes capability-protected CMS application services through the official PHP MCP SDK. The same server runs over authenticated Streamable HTTP and local stdio. MCP handlers call Kumwe application services; they do not query tables, edit files, or bypass workflow, authorization, revisions, optimistic versions, or audit records.

## Streamable HTTP

The endpoint is `https://cms.example.org/mcp`. Send a Kumwe bearer token on every request:

```http
Authorization: Bearer TOKEN
```

The transport validates the exact host, Origin/CORS behavior, MCP protocol version, request body limit, and persistent session. The route requires authentication; each tool then requires its own capability. A settings-only token can use settings tools without also receiving content access.

## Local stdio

Create a short-lived, least-privilege token and store it in an absolute owner-readable file. Configure the client to launch:

```bash
php bin/kumwe mcp:serve --token-file=/run/secrets/kumwe-mcp-token
```

Stdio does not grant implicit local administrator power. The token is verified for expiry/revocation and its capabilities apply to every tool. Run the process as a dedicated unprivileged service account; never give an MCP client database-administration credentials, signing private keys, backup keys, or unrestricted extension/media filesystem access.

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
| `kumwe_settings_get` | `settings.manage` | Read browser-managed site settings |
| `kumwe_settings_update` | `settings.manage` | Update all validated site settings |
| `kumwe_user_list` | `users.manage` | List users, groups, and assignments |
| `kumwe_token_list`, `kumwe_token_revoke` | `users.manage` | Inspect token metadata or revoke access |
| `kumwe_extension_list` | `extensions.manage` | List installed extensions and state |
| `kumwe_extension_activate` | `extensions.manage` | Activate an installed extension and runtime map |
| `kumwe_schedule_list` | `automation.manage` | List schedules |
| `kumwe_schedule_create` | `automation.manage` | Create a validated recurring schedule |

High-risk operations that would transmit a password, install an arbitrary package, delete state, or grant permissions are intentionally not MCP tools. Use the administrator, protected CLI, or REST endpoint with the operation's explicit safeguards.

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

Use [REST](rest-api.md) for the complete machine-to-machine API and [Architecture: delivery](architecture/delivery.md) for the shared-service contract.
