# MCP

Kumwe exposes the same server over local stdio and authenticated Streamable HTTP.

## Local stdio

```bash
php bin/kumwe mcp:serve
```

Configure an MCP client to launch that command from the Kumwe release directory with the normal Kumwe environment. Stdio inherits the operating-system account's access; run it under a dedicated unprivileged service account.

## Streamable HTTP

The endpoint is `https://cms.example.org/mcp`. It requires a Kumwe bearer token with `content.read`. Every request is protected by exact-host validation, origin/CORS handling, MCP protocol-version validation, the application body limit, and persistent MCP sessions.

The shipped MCP surface provides:

- capability discovery;
- a `kumwe://capabilities` resource;
- content, SEO, site-structure, and extension-compatibility review plans;
- a site-review prompt.

Review plans are deliberately non-executable. Apply content changes through the REST API, where capability checks, ETags, idempotency records, workflow rules, revisions, and audit records are enforced. Do not give an MCP client direct PostgreSQL, extension-volume, or secret access.
