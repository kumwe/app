# Machine-contract boundaries

Kumwe has one core and several adapters. Their contracts are related, but they are not interchangeable SDKs.
All delivery adapters call the same application services, so a capability, authorization decision, refusal,
transaction, audit event, and idempotency rule stay authoritative in core rather than being reimplemented per
transport.

## Contract map

| Contract | Audience and purpose | Authority | Version 2 position |
|---|---|---|---|
| **Extension SDK** | Trusted PHP extensions installed into the Kumwe runtime. It lets an extension declare and register capabilities that extend core. | Manifest schemas 1–5, contribution SPI revisions 1–3, classified PHP public types, compatibility fixtures, lifecycle rules, and `sdk/extension-conformance` in this repository. | Gate A contract. Existing generations remain byte- and signature-compatible; incompatible change requires a new additive generation and a migration path. |
| **Client integration SDK** | External applications, including future Flutter clients, that communicate across a process boundary. It helps a client consume supported wire contracts; it does not load extension PHP or resolve core services. | Authoritative server contracts live here. The Dart proposal and client-side conformance work live in `kumwe/dart-sdk`. | Version 3 lane N. It is not the extension SDK, a Gate A deliverable, or Gate A evidence. ADR 0009 governs adoption. |
| **REST API** | HTTP integrations and external application clients. | Retained OpenAPI generation and the closed problem-details registry, produced from the live route/application declarations in this repository. | Public Version 2 wire contract. Existing operation, schema, problem type, and semantic digest bytes are protected by executable compatibility checks. |
| **CLI** | Operators, installers, automation processes, workers, and schedulers invoking the installed release. | The retained CLI generation closes command/action names, input grammar, secret transport, portable exits, output metadata, exit meanings, and action risk. The dispatcher enforces input and declared exits; the exact retained artifact and live registration parity protect the complete metadata surface. | Public Version 2 operational contract. It is an adapter over application services, not the extension bridge or the Dart SDK. |
| **MCP** | Approved AI clients and local tools using stdio or Streamable HTTP. | The retained MCP generation describing tools, resources, prompts, schemas, risk, mutation requirements, and stable error envelopes, checked against the live server. | Public Version 2 machine contract. It never bypasses application authorization, mutation, idempotency, or refusal rules. |

The extension SDK may expose a typed application-service allowlist *inside* the trusted runtime. REST, CLI, and
MCP cross a delivery boundary and expose serialized operations instead. The future Dart SDK consumes those
external contracts; it never becomes an in-process extension API. A generated REST, CLI, or MCP surface can be
declared by an extension, but that does not merge the transport contract with the extension SPI: the signed
extension declaration is admitted first, and the core adapter publishes the resulting operation under its own
machine-contract rules.

## Compatibility rule

Each retained generation is immutable. A change may add a new generation while preserving every supported
earlier generation, or it may make a compatible addition where that generation explicitly permits additions.
Removing a public member, narrowing accepted input, widening authority, changing an existing serialized meaning,
renaming a stable error, or changing a recorded digest is incompatible and requires an explicit successor plus a
migration path. Generators are deterministic and verification compares retained artifacts with the live runtime;
documentation alone cannot declare compatibility.

A REST successor must use new retained core, OpenAPI artifact, problem-registry, and compatibility-fixture
paths. A route-exclusion path may be shared only while its SHA-256 remains identical. Run
`composer openapi:accept-generation` only after compatibility review; the ledger refuses replacement bytes,
out-of-order versions, reused generation-owned paths, and a shared exclusion path whose meaning changed.

CLI actions carry one of four effect classes: `read`, `local-write`, `mutate`, or `high-impact`. They describe
the maximum direct effect of the action: observation; a local artifact/runtime-file change; a bounded ordinary
application mutation; or a credential, authorization, trust, installation/schema, recovery, broad execution, or
destructive operation. Risk is retained orchestration metadata, not an authorization substitute. The dispatcher
enforces the frozen input grammar and refuses an implementation exit absent from the generation; output format,
exit meaning, and risk remain compatibility-pinned metadata reviewed with the exact artifact.
`php tools/verify-cli-machine-contract.php --write` can establish a missing documentation mirror or confirm
byte-identical v1 content; it refuses different bytes at the retained path. Preserve v1 and add a successor
contract class and generation-owned artifact when an intentional compatibility change cannot fit v1.

For MCP, `php tools/generate-mcp-machine-contract.php --write` only establishes a missing generation artifact or
confirms that an existing artifact already has identical bytes. It refuses to overwrite a retained generation.
Change `McpMachineContract::GENERATION`, introduce the corresponding successor artifact and migration guidance,
and continue serving every supported earlier generation when an incompatible MCP change is intentional.
The generated `tool_error.registry` is the finite vocabulary the runtime mapper uses: every retained code, safe
message, retry decision and feasible exception/stable-code classification is included in both its own digest and
the generation's primary `contract_sha256`.

## Production `app` and `web`

`compose.production.yaml` separates two runtime responsibilities built from this repository and its one source
tree:

| Service | Image stage | Responsibility | Code location |
|---|---|---|---|
| `app` | `runtime` in `docker/php/Dockerfile` | PHP-FPM, domain and application services, persistence, extension runtime, and every dynamic delivery handler. | `src/`, `config/`, `templates/`, `resources/`, and the installed dependencies in this repository. |
| `web` | `web` in `docker/php/Dockerfile` | Nginx public edge: serves the built public assets and forwards dynamic requests to `app` over FastCGI. | `public/` plus `docker/nginx/default.conf`, copied from the same build. |

They are not separate Kumwe applications and do not have separate product codebases. `worker`, `scheduler`, and
`migrate` reuse the `app` runtime image with different commands. The split keeps the public edge small while PHP,
database credentials, private storage, and trusted extension execution remain on the backend network.

## References

- [Extension development](../extensions.md) and [business integrations and extension SDK](../business-integrations.md)
- [Delivery surfaces](../architecture/delivery.md), [REST](../rest-api.md), [CLI](../cli.md), and [MCP](../mcp.md)
- [ADR 0009: native client platform and authentication link](../roadmap/decisions/0009-native-client-platform-and-the-authentication-link.md)
- [`compose.production.yaml`](../../compose.production.yaml) and [`docker/php/Dockerfile`](../../docker/php/Dockerfile)
