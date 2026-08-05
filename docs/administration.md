# Administrator

The graphical administrator is available at `/administrator`. It manages site content, navigation, users and access, public settings, extensions, and templates. The interface shows only actions allowed by the signed-in user's effective capabilities, and the server repeats that authorization check for every request.

Production sessions use secure, HTTP-only, same-site cookies. Every browser mutation is a POST protected by a CSRF token. Updates create audit records; content and navigation use optimistic versions so an old browser tab cannot overwrite newer work.

## Dashboard and navigation

The administrator navigation links to:

- **Content:** page list, editor, workflow actions, trash, and restore;
- **Content models:** versioned content-type schemas and workflow definitions;
- **Navigation:** menus and nested menu items;
- **Users and access:** users, groups, capability grants, assignments, and API/MCP tokens;
- **Extensions:** package upload, activation, disable, and uninstall;
- **Automation:** schedules, recent jobs, retries, and cancellation;
- **Settings:** public site configuration;
- **Sign out:** invalidates the current administrator session.

A missing section normally means the account lacks its management capability. A direct request to that route receives `403` rather than relying on hidden navigation.

## Content and publishing

Each edit creates an immutable revision and audit event. The built-in workflow supports these actions:

| Action | From | To | Capability |
|---|---|---|---|
| Create | — | Draft | `content.create` |
| Edit | Non-trashed record | Same state | `content.update` |
| Submit | Draft | Review | `content.submit` |
| Return for changes | Review | Draft | `content.review` |
| Publish | Draft or review | Published | `content.publish` |
| Unpublish | Published | Draft | `content.unpublish` |
| Archive | Active state | Archived | `content.archive` |
| Restore archive | Archived | Draft | `content.restore` |
| Trash | Any active state | Trashed | `content.delete` |
| Restore trash | Trashed | Draft | `content.restore` |

The editor displays only valid actions for the current state. Public page URLs are `/pages/{slug}`. Slugs are unique within the selected site and use lowercase letters, numbers, and hyphens.

Pages store a title, slug, workflow state, optional publication window, and structured JSON data. A basic page commonly uses:

```json
{
  "body": "<p>Welcome to our website.</p>",
  "summary": "Homepage introduction"
}
```

Templates decide how fields render. Apply an organization-approved rich-text sanitization policy to any custom editor that stores HTML.

Open **Content models** to create content types and workflows or publish a new version of an existing definition. Content types declare their JSON field schema and select a workflow version. Workflows declare states, their public visibility, and capability-protected transitions. The content editor validates its structured JSON against the pinned schema and renders the valid transitions from the pinned workflow. A definition update never changes the meaning of existing content; incompatible field or state removal requires an explicit breaking-change confirmation and still creates a new immutable version.

## Menus and navigation

Open **Navigation** to create a named menu, change its handle or title, and create nested items. An item has a title, slug, parent, position, computed path, and optimistic version. Kumwe rejects cycles and rebuilds descendant paths when parentage changes. Deleting a menu deletes its contained items only after explicit confirmation.

Menu management requires `navigation.manage`. Template or module code reads navigation through the navigation application service; it must not query navigation tables directly.

## Users, groups, and permissions

Open **Users and access** to:

- create users with an initial password and status;
- update display name, email, and status;
- create groups (roles);
- assign or remove a user from a group;
- grant or revoke a capability on a group;
- issue a capability-scoped API/MCP token for an existing user;
- inspect token metadata and revoke active credentials immediately.

Grants can be global or scoped to a component or content type. Prefer task-specific groups such as Editors, Reviewers, Publishers, Extension managers, and Operators instead of sharing an all-powerful account.

Kumwe prevents an administrator from disabling their own account or removing their own administrator role through the same request. Keep at least two separately controlled owner accounts for production recovery.

Tokens are shown once and stored only as a digest. Copy a new token directly into its destination secret manager. Give it the smallest capability set and an expiry; never paste tokens into issue trackers, templates, or content fields.

## Capability catalog

| Area | Capabilities |
|---|---|
| Administrator | `administrator.access` |
| Content reads and edits | `content.read`, `content.create`, `content.update`, `content.delete` |
| Workflow | `content.submit`, `content.review`, `content.publish`, `content.unpublish`, `content.archive`, `content.restore` |
| Site structure | `navigation.manage` |
| Identity | `users.manage` |
| Configuration | `settings.manage` |
| Extension lifecycle | `extensions.manage` |
| Jobs and schedules | `automation.manage` |

Capabilities are independent. For example, a reviewer may read and return content without publishing it, while a publisher may publish but not manage users or extensions.

## Site settings

Users with `settings.manage` can change the site name, homepage, default locale, timezone, and search-indexing policy under **Settings**. The homepage must identify a published, non-trashed page inside its publication window; until it is available, the public site renders a setup-safe placeholder. Disabling indexing changes the dynamic `/robots.txt` response and adds a no-index header to public pages; it does not make published content private.

Database credentials, Redis credentials, trusted proxies, application secrets, release identity, and container images remain deployment configuration. They are intentionally not editable in the browser. See [Configuration](configuration.md).

## Extensions and templates

Open **Extensions** to upload an extension ZIP, inspect installed versions, activate or disable an extension, or uninstall it. Production accepts packages signed by an enabled trusted key. Development may allow unsigned local packages with `EXTENSIONS_ALLOW_UNSIGNED_LOCAL=true`.

Installation stages files outside the public root, applies checksum-bound declared migrations, records the disabled release, and publishes an immutable runtime generation. Activation is a separate operation. Site theme selection is owned by the selected site; the administrator theme is installation-wide. Replacing one assignment disables the previous theme only when no other site or surface still uses it. Administrator-theme activation, disablement, and uninstall require current-password step-up authentication. Active themes must be disabled for every assigned site or surface before upgrade. Replicas reconcile the authoritative database publication at startup and remain unready until the locally loaded generation is trusted and current. Restart long-running workers after any activation or removal.

See [Extension development](extensions.md) and [Template development](templates.md).

## Automation

Users with `automation.manage` can create, enable, disable, or delete recurring schedules and inspect recent jobs. Dead jobs may be retried after correcting the cause; pending jobs may be cancelled. Job payloads are validated JSON and job types come from the registered handler catalog.

The browser manages durable records only. Use the deployment platform to start, stop, or scale workers and schedulers. See [Workers and scheduler](automation.md).

## Operational tasks

The administrator manages site state; host-level operations remain deliberately outside the browser. Operators use the CLI or deployment platform for migrations, worker process lifecycle, backup and restore, secret rotation, image replacement, and first-owner creation. This keeps server authority out of ordinary browser sessions.

Use [Workers and scheduler](automation.md) for durable automation and [Operations](operations/README.md) for production procedures.
