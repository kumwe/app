# Changelog

Everything this programme has **finished** is recorded here. Everything it has **still to do** lives in
[`docs/roadmap/`](docs/roadmap/README.md) — objectives, gates, work packages and the open findings ledger.
Those are the only two places, and they never overlap: a work package leaves the roadmap and arrives here in
the pull request that completes it. If you want to know what is coming next, read
[`docs/roadmap/STATUS.md`](docs/roadmap/STATUS.md); if you want to know what already shipped, read this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries cite the commits that carried them. Version 2.0.0 is not released, so everything below sits under
`Unreleased`; the release simply renames that heading and opens a new one.

---

## [Unreleased]

Kumwe 2.0 built from scratch: a content management system and a business application platform served by one
set of application services, one composition root, one authoritative relational transaction, and one
authorization decision in front of every read and every write. This block covers the whole of the 2.0
development programme, from the architecture decision that opened it to the current head of `master`.

### Added

- **A business-group installation: several businesses on one Kumwe, sharing what they choose to share.**
  A resource's owner is now held at a *level* — one site, a declared group of sites, or the installation —
  rather than always at a single site. Every resource still has exactly one owner, so "who owns this?" keeps
  one answer and every denial and audit entry still names it. Groups are declared, never inferred: an
  operator writes down which sites are in one, groups may overlap freely, and both inclusion and exclusion
  are gated on the installation-wide `sites.group.manage` capability and audited. A group cannot be emptied
  of its last member, because everything it owns would become unreachable. An installation that never
  declares a group behaves exactly as it did before. (`e46104b`)
- **Accounting isolation that an operator cannot switch off.** Which ownership levels each kind of record may
  be held at is fixed in the build, not in configuration: accounting documents, ledgers and pay runs are
  site-owned only, while clients, people, price lists and products may be widened to a group. The table
  covers every category this build carries, an undeclared category falls back to isolation, and an extension
  may declare its own once but may not restate a reserved one. An owner is assembled through
  `ResourceOwnership::of()`, which refuses an impermissible pairing at construction, so no code path exists
  that could write one; on the engines that support a table check constraint the row itself refuses to spell
  no owner or two. (`e46104b`)
- **Widening and narrowing a record's owner, deliberately asymmetric.** Sharing changes after the fact with
  no rewiring and no data movement — the record stays where it is and only the scope that owns it changes.
  Widening costs an ownership change and an audit entry. Narrowing first proves that nothing in the sites
  about to lose access still refers to the record and **refuses with those sites named** when something does,
  rather than silently orphaning them. Both directions are gated on `ownership.scope.manage`, both are
  audited, and both write through a compare-and-set on the current owner so two operators changing the same
  record produce one change and one refusal. Leaving the installation scope is refused outright, because
  its membership is every site there is and an unbounded guard would answer that nothing is stranded for
  the wrong reason. Extensions contribute their own reference inspectors, so a narrowing is judged against
  every kind of reference the installation actually holds. (`e46104b`)
- **Consolidated group reporting as a distinct read capability.** `reports.consolidated.read` is bound to the
  group and to nothing else, so holding it lets a report read across a group's member sites and authorizes no
  write anywhere, in any business, of any kind. Isolation stays at the write layer and unification happens at
  the read layer; no transaction spans sites, and a transfer between two businesses of a group remains two
  transactions coordinated by a durable event. (`e46104b`)
- **[Business groups](docs/business-groups.md),** explaining the model to an operator, stating the widening
  and narrowing asymmetry plainly, and telling an extension author the four things to do to make a new record
  category take part. (`e46104b`)

- **A consolidated programme roadmap with a machine-readable findings ledger.** Six competing plans became
  one authority for sequencing: two gates, ten decisions, an enterprise capacity contract, a per-primitive
  judgement of what an enterprise resource planning system needs against what the code actually provides, and
  an architecture decision record for resource-ownership scope. (`45b0fee`)
- **This changelog, and the lifecycle rule that keeps it and the roadmap apart.** `docs/roadmap/` holds
  forward work only; a completed work package leaves it and lands here in the same pull request that completes
  it, so the roadmap shrinks as the programme advances instead of accumulating a tail of finished items. The
  rule is stated at the top of the roadmap and beside the pointer in `AGENTS.md`, and enforced by
  `tools/verify-roadmap.php` — `composer roadmap:check`, inside `composer qa`, and again from the architecture
  suite — which fails when `findings.json` carries an entry in state `closed` and names what to move where.
  The fifty-six findings already closed at consolidation were moved out of the ledger and into the entries
  that follow.
- **A structured observability contract that the runtime is obliged to honour.** `config/observability.php`
  had declared a JSON format, a level, a required context set and a redaction list that nothing read.
  `ObservabilityContract` is now the single reader of that file, loaded once at composition, and the logger,
  the metric catalogue and the endpoint policy are all built from it — so a declaration the runtime cannot
  honour stops the boot rather than quietly meaning nothing. (`309db88`)
- **JSON logging with nested redaction, correlation, causation and W3C trace context.** Every record carries
  release, runtime surface, derived outcome and the request, correlation and trace identifiers; redaction runs
  last, is key-based, and applies at every nesting level. An attached throwable is reduced to class, file, line
  and a message scrubbed of URI credentials, with no stack trace, because frame arguments carry exactly the
  values the key rule removes. A well-formed `traceparent` joins the log stream to an upstream trace without a
  vendor dependency. (`309db88`)
- **A protected Prometheus metrics endpoint with enforced cardinality.** `GET /metrics`, off by default,
  exposing counters and histograms held in Redis plus twenty-four gauges recomputed from durable rows per
  scrape. Every label value is enumerated and anything else folds to `other`; undeclared labels are dropped and
  a forbidden label fails at boot. There is no path, route, site, user or record label. The endpoint is
  fail-closed in three steps: absent unless enabled, `404` rather than `401` when enabled without a token so a
  misconfigured deployment does not advertise itself, and a constant-time bearer comparison otherwise.
  (`309db88`)
- **Machine-readable alert rules with runbook references.** Each rule names the runbook section that says what
  to do, and states the concrete failure it would have caught. (`309db88`)
- **`KUMWE_LOG_LEVEL`, so log verbosity no longer rides on the debug disclosure flag,** together with direct
  tests for the readiness, liveness and health-command contracts a load balancer and a start-up probe branch
  on. (`309db88`)
- **Real failure and recovery drills that take something away.** A killable relay sits on the network path to
  Redis and to the database and is `SIGKILL`ed, so established connections are reset and reconnection is
  refused — the state a stopped server leaves a client in, which a throwing stub cannot reproduce. A live
  worker holding a real lease on a real job is killed mid-handler and a successor is refused until the dead
  holder's lease expires on the wall clock. A server-side session termination covers failover. A real `SIGALRM`
  aborts a handler that would never return. A restore that has begun publishing targets is killed and re-run.
  (`f8b856e`)
- **A restore completion manifest and a target claim,** so re-running an interrupted restore recovers it with
  no manual cleanup while a target no claim names is refused untouched. (`f8b856e`)
- **A wall-clock deadline on the integration worker,** drawn from four fifths of its dispatch lease rather than
  all of it, so an effect cut short is still recorded under a fence that is still its own instead of racing a
  sibling's re-delivery. (`f8b856e`)
- **Gapless-on-commit business document numbering.** `core.sequence` is a server-allocated field type whose
  declaration is closed by construction — the definition validator refuses one that is not server-only,
  read-only, immutable after create, required and unique, that declares a default or a formula, or that is
  narrower than the widest number its format can render. Its configuration chooses tenancy scope, reset period,
  prefix, padding and the timezone the period boundary is judged in, so `INV-2026-000001` rolls over when the
  business's year does rather than when UTC's does. The allocator opens no transaction of its own: it joins the
  record command's, behind the mutation fence and after the access plan, so the number, the row, the revision
  and the audit entry commit together and a rolled-back command hands its number straight back. (`256888f`)
- **Contention drills that force real cross-connection races on a real engine.** Competing approvers, deadlocks
  built across two operating-system processes, worker termination between an external effect and its fenced
  settlement, and the idempotency first-claim race, each with the interleaving forced rather than hoped for,
  usually by bounding the rival's lock wait so a blocked session says so within a second. (`c0bd0a1`,
  `6e0d2e2`, `8bb8cc4`)
- **A signed bill of materials and a provenance statement inside every extension package.** `extension:build`
  embeds a CycloneDX 1.6 inventory at `kumwe.sbom.json` and a SLSA-shaped statement at `kumwe.provenance.json`
  as ordinary archive entries, so the package digest covers both and the existing detached Ed25519 signature
  vouches for them without a second signature format. Neither document carries a timestamp, so byte
  reproducibility is unchanged and proven so. (`5bf08c2`)
- **Admission-time package scanning.** `PackageAdmissionScanner` runs one bounded pass over the staged snapshot
  between the trust decision and extraction: every entry is digested and reconciled against the bill of
  materials, the provenance statement is bound to that inventory and to the manifest, and the packaged PHP is
  checked by the same conformance rules the SDK runner uses, so `extension:conformance` and admission produce
  identical findings. A package carrying neither attestation installs and records them absent, so packages
  built earlier keep working. (`5bf08c2`)
- **An upstream trust-revocation feed.** An operator pins an issuer origin and Ed25519 public key, and
  `extensions.trust.revocations.synchronize` consumes a signed list with a monotonic sequence and a freshness
  window, emergency-revoking each withdrawn key still trusted locally. The verification key is pinned in
  configuration and never read from the trust store, because the store is what the feed revokes. (`5bf08c2`)
- **A tamper-evident audit trail.** Every `audit_events` row carries a canonical SHA-256 digest of its own
  fields, so a mutated row fails its own recomputation, and a `previous_digest` witness link to whichever row
  was head when it was written, so deleting a row breaks the link that named it. The link is read with a plain
  snapshot select rather than a lock, because chaining under a lock would put every mutating transaction in the
  installation behind one row. (`05ff831`)
- **Monotonic audit positions and a sealed anchor ledger.** The database allocates the position — auto-increment
  on MySQL and MariaDB, an identity column on PostgreSQL — and a scheduled job seals settled position ranges
  into a chain of their own, fixing each range's row count and rolling digest, which is what makes an insertion,
  a deletion or a reordering inside a sealed range evident. Only rows older than a settle window are sealed,
  because a position allocated inside a still-open transaction can commit after a range that already covers it.
  (`05ff831`)
- **`audit:verify` and `audit:export`.** The verifier walks the whole chain and reports the first divergence in
  its exit status, and runs nightly as a job that fails loudly rather than logging. The exporter preserves a
  range as a checksummed, redacted NDJSON archive in private `0600` storage and records the export in the trail
  itself, so incident preservation no longer needs raw database access. (`05ff831`, `1e8bc12`)
- **Audit retention that archives, anchors and only then prunes whole aged anchored ranges,** shipping disabled
  with a zero window so an installation that never configures one keeps its trail forever. (`05ff831`)
- **A record-secret key ring with retired keys and a key-provider port.** One active key that new writes use,
  plus every retired key still needed to open what it sealed. Resolution is by the identifier the envelope has
  always recorded, never by trial, so a key the deployment no longer holds fails as `SecretKeyUnavailable`
  rather than as a decryption error — the difference between "restore the key" and "investigate tampering".
  Key acquisition is the `SecretKeyProvider` port, with the local ring as its production-capable default and the
  guarantees an external KMS or HSM adapter owes written on the port itself. (`a669846`)
- **`business-record-rekey` and the matching background job,** which re-seal stored envelopes under the active
  key. A pass is bounded, so it schedules beside live traffic; resumable without state, because the selection
  predicate is `key_id <> active` and a re-sealed row stops matching it; idempotent; and safe under concurrency,
  since an ordinary write that replaces a secret between the read and the update has already sealed it under the
  active key and the guarded update matches no row. The row's optimistic version is deliberately neither checked
  nor bumped, because re-keying changes no business value and must not manufacture a conflict. (`8706736`)
- **A full credential lifecycle.** A person replaces their own password by re-proving the current one through
  the existing high-impact credential guard, under the throttle that already guards sign-in. An administrator
  resets somebody else's without that proof, but only under `users.manage` on the exact record, with a mandatory
  reason and an audit event whose actor is not its subject — and never on their own account. The console carries
  both forms for runbooks. (`4bc5c74`)
- **Second-factor retirement and recovery-code reissue.** Retiring destroys the unspent recovery digests, keeps
  the consumed ones as evidence, records the reason on the row and lifts the block on re-enrollment. Reissue
  replaces the whole set and accepts an authenticator code alone, because one leaked recovery code must not be
  able to mint ten replacements. (`4bc5c74`)
- **A break-glass console credential-recovery path.** Every in-application reset is step-up-gated, so a total
  lockout has no in-application answer; the answer is the console, where reaching the host is the authorization.
  It acts as a dedicated credential-recovery system identity rather than widening the bootstrap one, takes the
  same locks, advances the same epoch and writes the same audit events as the screen. (`4bc5c74`)
- **`composer security:secrets`,** the same pinned secret scan the security workflow runs, over the branch's
  whole history rather than its working tree, so a literal introduced by an earlier commit is caught before the
  push instead of by CI. It prints each finding with the fingerprint the allowlist takes, and separates leaks
  found from a scanner that never started. (`c9bc5fe`)
- **A `document` view kind for business records.** The view vocabulary gains a sixth kind and an optional typed
  `document` block naming which declared parts play which documentary role: the field carrying the human number,
  labelled groups of meta fields, party relationships, the owned-line collection that becomes the body table,
  and the fields shown as totals. Every role is proven against the owning entity at construction, the block is
  omitted from canonical output when absent so existing published checksums are untouched, and a document view
  may not bind a custom handler. A print stylesheet drops navigation, tabs and actions so browser print yields
  the document alone. (`72126b0`, `45e1963`)
- **Queued CSV export from generated list views.** One deterministic record-set export report is derived per
  installed business definition from its declared exportable scalar fields, and request, authorization, scope
  resolution, policy snapshot, queueing, generation, status, download and audit all flow through the existing
  shared pipeline. The development server now supervises an exports worker beside the HTTP server, restarting it
  whenever a runtime generation change retires it, so a developer clicking export watches it complete.
  (`46c6b6c`)
- **A per-package map of where every extension contribution surfaces.** Administrator screens and reports linked
  at the exact paths the route registries mount them on, portal views below `/portal/extensions`, background
  listeners, consumers, jobs and schedules naming their queue or worker, theme surfaces reporting activation
  state, record and field types linked to their workspaces, and contributed capabilities pointing at the screen
  where somebody must still grant them. The route registries became the single public path authority, so the
  summary keeps no second copy. `extension:install`, `extension:activate` and the demonstration installer print
  the same lines. (`0635614`)
- **The production-qualification gap matrix,** opening the campaign with a threat, control and gap analysis of
  the merged runtime across eight control domains, each gap carrying its severity, evidence, fix shape and
  effort. (`734c62b`)
- **One-command demonstration install.** `demo:install` runs the access cast and the example extensions behind
  one authentication and one execution context. The credentials file keeps an owner-only exclusive-create
  contract and is written only when the run actually generated a new password, so a re-run confirms every
  account and example, reports that existing sign-ins remain valid, and touches no file. Closing output names
  the credentials file, both sign-in surfaces and the migrate-time datasets. (`53fa34c`, `31a45d5`, `d44c7f5`)
- **`demo:export-profile`, which projects a running installation back into an installable profile.** Site
  content, the business dataset with its definition documents in dependency order, and the access cast, all
  walked through the authorized application services rather than the tables, reusing ledgered fixture keys,
  idempotency keys and whole applied operation requests byte for byte while a resource is still at the version
  the installer left it — so an exported installed dataset stays diffable against its source manifest. The
  access export withholds every identity outside the reserved documentation zone and never exports credentials.
  (`ef3f49c`, `af15020`)
- **Selectable demonstration datasets.** Independently selectable documentation, placeholder, blank and business
  demo profiles with durable provenance, upgrade-safe reconciliation and deployment configuration, grown into a
  six-organization commerce graph of products, quotations, invoices, subscriptions and domains with ordered
  owned lines, per-definition record-access modes, and a demonstration staff and portal cast. Acceptance
  evidence pins the dataset counts, so an expansion that forgets to update them fails rather than drifts.
  (`6a9e57f`, `a3c4240`, `9f92a82`, `fc45992`, `31a45d5`, `9bb91c1`, `dda882b`, `abe2d74`, `7e52280`,
  `d4cc2fb`, `8d77f98`, `255ca20`, `7cb48f9`, `6e23c49`, `1749d42`, `bf6eee2`, `9d3f6e5`, `e3c394f`,
  `324c51a`)
- **Six document-driven public content layouts** — document, guide, reference, FAQ, landing and article — each
  with its own closed JSON schema, selected from the record's content type through a layout catalog. Document,
  guide and reference share a sticky section navigation with scroll-spy highlighting; unknown and historical
  types keep the general page layout. (`1f3c42e`, `8f1e602`)
- **Per-menu template and colour-scheme binding.** A menu item may override either for the page it links, both
  validated as handles on the way in and degrading to the defaults when they name a layout or scheme that no
  longer exists, so a stale binding can never fail a page. (`dfb64d2`)
- **Repeatable-group editing in the administrator content editor.** Structured layout fields such as document
  sections, guide steps and reference entries were previously authorable only through the API; the editor now
  renders them as repeatable rows with add-and-remove controls, discovers submitted rows from the body, closes
  the gaps removal leaves, and drops untouched blank rows rather than storing them. (`d87bab3`)
- **A branded site theme example.** A complete installable template package with its own palette, typography,
  layout, page and home surfaces and stylesheet, proving the template override boundary and passing static
  conformance with no violations. The example installer became type-aware: a template package installs so it is
  selectable but is never activated onto the site, because restyling the public site stays an operator decision.
  (`25951aa`, `fb6b41f`)
- **Kumwe Interface Standard 1.0 as an enforced runtime contract,** not a style guide: typed surface
  declarations, a shared server-rendered component and token layer with focused enhancement, durable
  presentation preferences, versioned template compatibility, and template conformance checked for both site and
  administrator packages. A surface declares the earliest interface version it needs and a declaration requiring
  an unsupported version is rejected rather than rendered approximately. (`a2de9ff`, `1954ffd`, `ba1258b`,
  `4c0ee7f`, `1f670c2`, `8e07f48`, `276ee32`, `e5f212c`, `a00a1db`, `4ca2043`)
- **An interface-programme evidence gate,** `composer interface:programme`, dependency-free so surface,
  navigation, template, manifest, generated-definition, actor, journey, phase and evidence coverage stays
  enforceable before Composer dependencies exist, with waivers bound to findings. (`8253bf8`, `a0b40af`,
  `c7b4e9e`, `0b2b380`, `ac47f06`)
- **The business workspaces and the generated business surfaces migrated onto that standard,** with parity
  manifests asserting the migration rather than asserting screenshots, and qualification evidence bound to
  executable test bodies with explicit browser-evidence boundaries instead of narrative checkpoints.
  (`74241a0`, `9c5c669`, `a58411b`, `4fe3715`, `a39bb3a`, `17c3780`, `c214424`, `fb10b0c`, `452a96f`,
  `519b428`, `cb7ccbf`, `b05d82a`, `64d2dfa`, `a8483b0`, `3b9a785`, `233fec9`, `6d4d33a`, `5c52139`,
  `99663fe`, `13972f9`, `4221df9`, `6b2af4f`, `08eb0c7`)
- **Browser interface-integrity diagnostics** run as part of the browser suite. (`116eb17`)
- **Policy-aware generated business surfaces.** List, detail, form, history and relation views generated from a
  published definition, on the administrator and on an opt-in portal, with row, field and action policy applied
  before results, the same operation replay semantics as every other surface, approval and read-denial parity
  across both shells, and mobile choice controls that behave. (`adbffc7`, `c1877d6`, `e836cb7`, `d8e0bcc`,
  `3a968d5`, `6cdef9c`, `4d3494d`, `7e132b9`, `2f9bb65`)
- **Business security and an external portal.** Record-scoped policy, membership-derived portal principals,
  approvals and maker-checker, step-up re-authentication bound to purpose, site, organization, session and
  epoch, and portal isolation proven separately from administrator isolation, documented for operators and
  hardened across all three engines. (`725400d`, `c0870ce`, `ec143ff`, `4a23204`, `1ba19a2`, `fb9e033`,
  `1066ad8`, `70327bf`)
- **Durable integration and reporting contracts** — an outbox with source events and a sequenced journal, an
  inbox with consumer receipts and checkpoints, reports and read projections with bound authorization, and
  exports as queued, stored, checksummed artifacts. (`754572c`, `c79ff8d`, `3204b7b`, `303106e`, `c529a42`,
  `2e993a6`, `e382ead`, `14df3fe`)
- **A trusted integration runtime and an extension SDK,** including a scaffolder that generates a complete
  component package, a conformance runner, and ordered inspection relations materialised through the manifest
  contribution set. (`dafffec`, `0163613`, `625e1b4`, `a83d9be`)
- **A typed, transactional business record runtime.** Optimistic concurrency on every mutation, idempotent
  command replay with `key_reused`, `in_progress` and `corrupt` outcomes, revisions and history, exact-value
  field types, a bounded typed query compiler, and one authoritative transaction enclosing record, revision,
  audit and event effects. (`53bba97`)
- **A deterministic transactional business schema runtime.** Plan, approve, execute, recover and destructive are
  five independently grantable stages; approval binds to the plan checksum the operator inspected, so a plan
  that changed underneath fails instead of being applied. (`accb3b2`, `f79c938`)
- **A typed business definition catalogue** with immutable published definitions, a canonical-JSON checksum,
  twenty-six built-in field types, relationship kinds including relationally stored owned-line collections, and
  administrator screens for the whole lifecycle, with the graphical definition editor rendering new definitions
  safely and reaching its accessibility and keyboard-publication gates. (`b96058c`, `07dcf30`, `1cb29eb`,
  `5333cb0`, `a0b2c05`, `b419af9`, `bfeccb9`, `4b493ef`, `ffc2f7e`, `4b99ac1`, `71d1120`, `e8ab1b3`)
- **Four-surface parity for business definitions and schema plans** — administrator, REST, console and
  model-context tooling all driving the same application services, each schema stage naming only its own
  capability. Two operations are deliberately absent from the machine surface, because composing a destructive
  purge plan and approving a high-impact plan both require re-proving the caller's current password, which an
  agent surface must not be able to satisfy. (`f1c00ea`, `22cf0b3`, `52f3ab6`)
- **Typed extension contribution declarations wired at runtime,** with lifecycle and boundary verification, so a
  contribution kind cannot be discoverable while remaining un-removable on disable or trust revocation.
  (`3bb3bad`, `baa3eda`, `99e21ff`, `2f807a0`, `4b4b35b`, `aecd692`, `bfac85a`, `7710635`, `bdc6f98`,
  `43e7a57`, `fbd43e4`)
- **A graphical administrator experience,** server-rendered with focused enhancement, keyboard-operable
  throughout, responsive on small viewports, and covered by reviewed visual baselines and accessibility gates
  whose data is stabilised so a rerun compares like with like. (`bd13c94`, `080801d`, `78ed736`, `e0586a1`,
  `12f67b5`, `c9e7de3`, `875b424`, `e520645`, `95743d1`, `7996e14`)
- **Database-driven public pages and navigation,** replacing the file-shaped presentation with content,
  navigation and media the administrator owns, including sparse pages, an exact logo locator and portable
  primary-menu ownership validation. (`45e64c1`, `ef2f90b`, `1393f63`, `4189fd6`, `6fc3b3f`, `8ec241c`,
  `ca23b0c`, `9813859`, `b1df9a6`, `bf65ff2`)
- **Repeatable administrator provisioning,** isolated from the extension runtime so the bootstrap cannot depend
  on installed code, and proven for multiple administrators and for the recovery container. (`2144553`,
  `fda2c3e`, `6a1599e`, `48b5660`, `e150cc2`, `229f3fc`, `92da754`, `c914371`)
- **The unified coding standard and its enforcement.** `docs/coding-standard.md` became the single normative
  source for documentation blocks, tag order and alignment, type declarations, naming, structure and dependency
  direction, error handling and test expectations; `AGENTS.md` and `CLAUDE.md` delegate to it rather than
  restating it. (`49d28f7`)
- **Dependency-free documentation-block tooling.** `tools/verify-docblocks.php` fails when a documentable member
  lacks a block, a description, an `@since`, a `@param` or a `@return`, documents a parameter that does not
  exist, or exceeds the line limit; `tools/format-docblocks.php` applies the alignment rules mechanically.
  Neither needs Composer, so both run before `composer install` and inside minimal images, and both refuse to
  touch a file that pins its own bytes with a self-checksum. (`3852208`, `0b1dce1`)
- **A documented public API across the whole codebase** — every class, method, property, class constant and enum
  case carrying a block that says why the member exists, what its parameters mean to it, and under which
  condition it throws. (`4971aeb`, `cc0d884`, `d232743`, `7a035e2`)
- **A single-container application kernel.** One PSR-15 HTTP stack, one console entry point, Doctrine DBAL
  persistence and one composition root, replacing the inherited front controllers. (`65de712`)
- **Identity, authorization and audit as first-class domains,** followed by content, workflow and navigation,
  the secure extension platform, structured presentation and site building, and safe automation planning with
  jobs and schedules. (`c3f77ad`, `e49d722`, `b82fdd0`, `8cd6c97`, `4b0cd40`)
- **Scoped bearer tokens for API routes,** with delegation ceilings that acceptance exercises rather than
  assumes. (`f113028`, `1a4bbd2`, `477d41f`)
- **Programmable delivery surfaces** — REST with a generated OpenAPI document, a console with stable JSON and
  exit codes, and model-context tooling — all over the same application services. (`e719a9b`, `84f7336`)
- **A portable management runtime on three engines.** MariaDB, MySQL and PostgreSQL are all supported, with the
  same migration, recovery and acceptance contract on each, closed out against real engines rather than against
  a single development database. (`bb9206a`, `c723c7e`, `a850cc6`, `8b6c48c`, `bd96d0d`, `330a9b3`, `0286827`,
  `fdf0b88`, `f8dd569`, `2931deb`, `c3cc34e`, `5d75dc2`, `678413e`, `05a44e4`, `ef1ec93`, `1633a5b`, `dec321b`,
  `158e7ea`, `4517a35`, `4147d6c`, `0a61f3f`, `ecd8150`)
- **A quality gate wired into Composer and CI from the first week.** The architecture policy check, the coding
  standard, strict static analysis, deterministic lock validation and committed normalization evidence all run
  as named Composer scripts that CI consumes, rather than as instructions in a document. (`d88f8df`, `d2bcf11`,
  `dfaaa07`, `e83eeba`, `778c6e1`, `3dd3b1b`, `601f936`, `77594f1`, `6a5657f`, `c618741`, `7b0f5d9`, `0e5bcf7`,
  `469ae52`)
- **Production delivery: images, compose topology, release and security workflows, and operator
  documentation,** including deployment acceptance against tagged distribution installs that quiesces the
  system, refreshes its tokens, validates schema execution outcomes and confirms high-impact installation plans
  before it reports success. (`9924989`, `538b6ef`, `c8f916e`, `2ab472d`, `44b7fcd`, `fdd3019`, `f7b4e1b`,
  `e6d7b97`, `c2335bd`, `a81f83e`, `42a139b`)
- **Verified backups with real restore drills,** restoring into a clean database, comparing the recovery
  manifest, and verifying restored schemas semantically rather than by byte comparison, with cross-engine
  recovery and backup acceptance executed on each supported engine. (`72d5012`, `e5bdbe8`, `a2ee79a`,
  `15d3a5f`, `c07242b`, `bb7dd1c`, `273aa7b`, `f15185b`, `48640fe`, `53eb64a`, `5069db3`, `46c27c0`, `e198a79`,
  `079227c`, `f2e312a`)
- **Operator and extension documentation, and a task-focused project guide,** covering architecture, delivery,
  persistence, extensions, automation, administration, operations and the recorded architecture decisions.
  (`0f494eb`, `a37eca9`)
- **A price stored in one currency can be presented in another, and the presented figure says so.** Kumwe now
  owns the money conversion contract: what a conversion asks for, what it returns, and the rules it obeys. A
  converted amount is a different kind of thing from a stored one and cannot be mistaken for it — it carries
  the amount and currency it came from, the rate applied, the instant that rate was as at, the identity of the
  provider that supplied the rate, the rounding rule applied and the exact unrounded product that rule was
  applied to. It is not possible to build one without all of that, so an operator reading a figure can always
  tell whether they are looking at what was agreed or at what it is worth today, and reproduce the second from
  the first. The arithmetic is exact from end to end; no conversion passes through a floating-point number, and
  rounding is a declared step with a named mode rather than something that happens on the way past.
  Conversion is presentation and reporting only: it never writes back, and a converted amount offered where a
  stored money value belongs is refused. (`8acec2c`)
- **Exchange rates come from extensions, and Kumwe ships none.** A package declares the currencies it prices
  and its place in the resolution order in its signed manifest, implements one port, and registers it through
  the same contribution registrar every other extension surface uses. An external rate service, a manually
  administered table, a bank feed and a contractual fixed rate are all that same port, and none of them is
  wired into the product. A package cannot price a currency it did not declare, cannot attribute a rate to
  another package, and cannot supply a rate dated after the moment that was asked about. Rates disappear with
  their package on disable, uninstall or trust revocation, in the same sweep as everything else it
  contributed. With no rate package installed, a conversion is refused rather than guessed. (`8acec2c`)

### Changed

- **Cross-site isolation is decided by containment instead of string equality, and is provably no wider.**
  The authorization gateway used to compare the owning site identifier with the caller's; it now asks whether
  the caller's site is inside the owning scope. For a resource owned by one site — every resource on an
  installation that declares no group, and every accounting resource on one that does — the containment test
  is that comparison, on the same single value. A test enumerates every ordered owner, caller and grant
  combination over a set of sites and compares the gateway's verdict and its stated reason against the rule
  the change replaced, written out as a reference; a single disagreement fails the build. The existing
  isolation tests were not rewritten. An instance owned at installation level now requires an
  installation-wide human grant, which is the same requirement the type-level `installationGlobal` flag
  already expresses — the two are reconciled into one rule rather than left as two mechanisms. (`e46104b`)
- **The ownership registry stores a scope.** `resource_site_ownership` gains the level and the owning group
  beside the site it already carried; the primary key is unchanged, so one owner per resource stays
  structurally enforced. The forward migration gives every stored row the site scope it already meant, keeps
  the foreign key and cascade on the site column, re-derives that column's character definition from
  `sites.identifier` so the portability pin survives the alteration, and derives the same definition for the
  new group column and the group tables — because MariaDB and MySQL otherwise resolve a new table's character
  set from the database default and a correct-looking join fails as an illegal mix of collations. Group
  membership is resolved once per process from a bounded declared set, so the containment test issues no
  extra query on the authorization hot path. Reading stays fail-closed on exactly the old terms: no row means
  unowned, a disabled site's resources stop resolving, and a group whose members are all disabled resolves to
  nothing rather than to an empty owner. (`e46104b`)

- **The restore drill stops comparing bytes and starts using the restored system.** Every check the backup
  acceptance manifest performed was satisfiable by a restore booted with the wrong keys, because ciphertext,
  nonce and row digests are key-independent — an installation whose `APP_SECRET` was lost passed the whole drill
  and failed weeks later at the first sign-in with a second factor. The drill now decrypts a stored
  `core.secret` envelope through the production cipher, signs a restored limited operator in with its restored
  password hash, allows the one operation it holds and denies the one it does not, ages its session and refuses
  it, decrypts the restored TOTP credential through the step-up cipher, passes a live challenge and refuses the
  replay of that code and of a spent recovery code, then materializes the extension runtime, dispatches the
  schedule the backup carried and drains the job in fresh processes. (`687707c`)
- **Backup and restore documentation gained declared recovery objectives and a key-restoration order,** with the
  consequence of each wrong key stated, and the drill now records its measured quiesce and restore seconds so a
  declared objective can be replaced by a figure from real hardware. (`687707c`)
- **`audit:verify` has three verdicts instead of two,** because an intact trail means materially different
  things under the two enforcement postures: exit 0 is chain verified with the database refusing rewrites, exit
  2 is chain verified with no enforcement installed on this server, and exit 1 stays reserved for an actual
  divergence. Nothing that lacks the triggers can present the clean verdict any more. The nightly job
  deliberately does not fail on absent enforcement, because that is a standing property of the server and
  dead-lettering a job every night would train operators to ignore the one signal that is an incident.
  (`ae4b92b`)
- **Audit digests are taken over a canonical form on both sides.** Storage engines do not hand back the bytes
  they were given — one engine's native JSON column reorders object keys and restyles whitespace, another keeps
  JSON as text, and `occurred_at` is a `datetime(6)` on some engines — so digesting driver output would have made
  `audit:verify` report tampering on an untouched trail. (`f78bc3c`)
- **Record encryption has its own configuration, independent of `APP_SECRET`.** `RECORD_ENCRYPTION_KEY`, its
  identifier and its retired keys each have a `_FILE` companion resolved by the application rather than by the
  container entrypoint, so bare-metal deployments get the same mounted-secret discipline, and the two secrets
  can finally rotate on separate schedules. (`a669846`)
- **Mutation-plan tokens get their own key ring, label, identifier and injected type,** so a record rotation
  cannot re-key live browser-held tokens and a move to a managed key service cannot drag them along.
  (`a669846`)
- **Administrator browser sessions honour the security epoch.** The session row carries its issuing epoch,
  backfilled from its owner, and lookup compares it, so a revocation reaches a browser on its next request
  instead of at expiry — and break-glass gained the terminate-all-sessions operation it was missing.
  (`4bc5c74`)
- **Any published layout may lead the site.** The homepage invariant and both administrator pickers assumed the
  one-layout era; all three now accept any published content entry while keeping the published-within-window
  rule, and the landing layout gained an optional media-backed logo. (`cf1c5cb`)
- **The README is organised around the one-command demonstration** — what Kumwe is, a quick start that actually
  finishes, a clean-start path, how to run the workers queued exports depend on, the test gates, and how to
  contribute and extend. (`5ad13b3`, `a35f362`, `9f91a75`)
- **Demonstration profile releases are append-only,** with persisted checkpoints validated and policy provenance
  re-checked on every reconciliation, so an installed-then-customised site cannot be silently rewritten by a
  later profile version. (`cbde170`, `4fd54fd`, `7f176fb`, `eb0900b`)
- **Delivery parity is asserted against the live router rather than against source text.** The previous check
  read the composition root as a string and asserted substrings inside a fixed character window around each
  route name, so it could not see routes built by concatenation and passed on a class that imported a guard
  without calling it. It now checks only what the OpenAPI document can prove about itself, and whether a path is
  really routed and which capability it demands is asserted against a booted container. (`f1c00ea`)
- **Test toolchain moved to PHPUnit 13 and PHP_CodeSniffer 4.** `Assert::isType()` call sites now name the type
  they always meant, two stubs that constrained their arguments became mocks because that is an interaction
  assertion, every `with()` site states the invocation count it expects, and doubles with no expectations are
  created as stubs. (`0aeca85`)
- **Frontend build dependencies moved to vite 8.2.1 and the current Node typings,** with the committed bundles
  as the evidence: rebuilding reproduces every file under `public/assets/build` byte for byte, hashed names
  included, so no rendered page and no screenshot baseline can have shifted underneath. (`15f48cf`)
- **PHP 8.5 images use the bundled OPcache** rather than a separately built extension, verified at image build
  time. (`3d4c806`, `1cebcb6`, `57ecd52`)
- **Restore tooling matches the supported PostgreSQL major version,** installing verified client packages rather
  than whatever the base image happens to carry. (`6f2735d`, `549bd64`, `7c4885c`, `ef4582c`)
- **Copyright is attributed to Vast Development Method** across the source tree. (`dc35a63`)
- **A report column or an export artifact holding a converted amount now carries the evidence for it.** An
  export outlives the request that made it and is the record the recipient keeps, so a converted figure sent
  out as a bare number is provenance lost permanently. A report column declared as a converted amount carries
  the whole story in the cell — the presented figure, the amount and currency it came from, the rate, the
  as-at instant, the provider and the rounding applied — written so that somebody reading the downloaded file,
  with no access to the installation that produced it, can tell a converted figure from an agreed one and
  reproduce it. A bare number in such a column fails the column's own declared type, so it is a refused report
  rather than a quietly weaker artifact. This widens the export payload for that column type; existing report
  and export payloads are unchanged. (`8acec2c`)

### Fixed

- **A refused save no longer empties the form.** Filling in a long document and losing every value to a
  failure you could have recovered from was the single most expensive defect an operator met. Two gaps
  caused it. On the generated administrator and portal surfaces a validation failure already came back with
  the submitted values, but a stale-version conflict — somebody else saved the same record while your form
  was open — escaped as an error page and took the whole submission with it. The CMS content editor had no
  retention at all: both failures discarded the work, and for a new draft that meant everything typed was
  gone, because the draft existed nowhere else. Both surfaces now return you to your own form with every
  value still in it. A conflict says plainly that the record changed underneath you, names the version it
  is at now, and offers three things you can actually do: save again to apply your entries on top of that
  newer version, reload it and start over, or look at what changed first. Nothing is written on the way
  through, so the newer record is never silently overwritten and a hundred-line document loses no line. A
  write-only secret is still never echoed back, so that one field is re-entered; everything else survives.
  Operations that carry nothing typed — archive, delete, restore, an action confirmation — still fail
  closed, because there is nothing to keep and no form to return to. (`847576a`)
- **Record history could show one record's past under a reference another record had since taken over.** A
  business reference such as an invoice number can be used again once the record holding it has been deleted
  outright, and the revision log deliberately outlives the record, so a single reference can name more than
  one past. Kumwe already refused to merge two of them — but it decided how many there were by looking only
  at the page of history it had just read. Ask for a small page, or page back far enough, and the second
  record simply was not in view: the request succeeded and returned one record's history under a reference
  two records had held, with nothing on the page to say so. How many records a reference covers is now
  settled across the whole site and organization before any page is read, so the refusal is the same answer
  at every page size and every position in the log. Paging itself was tightened at the same time, because
  two records under one reference number their versions independently: history is now ordered on a key that
  can never tie, and a page boundary that lands between two entries agreeing on version repeats neither and
  skips neither. A new index carries that order, so the stricter guarantee costs a history page nothing.
  (`92f9305`)
- **A freshly created database could refuse to schedule work.** Site ownership is recorded in its own table,
  and the column naming the owning site was never tied to the site table's own identifier column. On MariaDB
  and MySQL that tie is only ever enforced by a foreign key — one a partially recovered installation may
  never have gained — and on PostgreSQL it is not enforced at all. Where a database had been created with a
  different default text collation, the two columns compared under different rules and the engine refused
  the comparison outright, which took the scheduler's dispatch pass down with it: due work simply stopped
  being queued. The ownership column now copies the site identifier's exact character definition, the
  migration proves the two agree before it finishes, and the check runs on every supported engine rather
  than being a MariaDB special case. The same repair gives the ownership constraint the per-installation
  name the recovery path already used, so the two routes that create it no longer disagree about what it is
  called. (`92f9305`)
- **The deployment drills could not load their own classes in the production image.** Production acceptance died
  on all three engines inside the restore drill's seed leg with a class-not-found error: the image installs with
  `--no-dev` and dumps an authoritative classmap, so nothing under the test namespace is loadable there even
  though CI bind-mounts the support directory. The entry point compensated with a hand-maintained require list,
  and the wave that made the drill decrypt, authenticate and execute added three classes to the harness without
  adding three lines to that list. Every cheaper job kept passing because they all run under the development
  autoloader, which is exactly why the break surfaced only after a full deployment was already up and why it
  looked engine-specific when it never was. The mapping is registered with the loader instead, and an
  architecture assertion refuses a hand-maintained list that grows back. (`26a7b39`)
- **A site-wide record-key rotation stranded the shared fixture database.** A rotation pass covers every
  installation of the caller's site, so the rotation drill moved every stored `core.secret` envelope in the test
  database onto a key whose material existed only inside that test's own process and was dropped at teardown.
  Everything that ran afterwards inherited a database whose secrets nothing could open — invisible while the
  backup drill only hashed ciphertext, because a stranded envelope hashes exactly like a readable one. The
  rotation is now rolled back through the same supported operation in the other direction, which is also
  precisely the shape an operator needs to abandon a rotation part way through. (`3fdb4e9`)
- **Package admission charged the memory ceiling per entry.** The zip reader asked the extension for the maximum
  entry size plus one on every entry, meaning to bound what an under-reporting header could make the process
  expand — but the call allocates exactly the length it is asked for and only shortens the returned string's
  recorded length when the entry turns out smaller, so every entry cost a full 64 MiB that was never given back.
  Admission holds two entries for the whole scan by design, so three files weighing a few kilobytes cost 192 MiB
  against a 256 MiB image limit and deployment acceptance failed identically on all three engines. Entries are
  now streamed in 256 KiB chunks with the ceiling enforced against the bytes that actually come out of the
  decompressor — a stronger guarantee than the old read, since an over-reporting header is now refused before a
  stream is opened at all. A regression test pins the cost: retaining every entry of a seven-entry package must
  stay under 8 MiB, where the previous implementation reports 448 MiB. (`cfaf840`)
- **The sequence counter column was a reserved word on MySQL.** `last_value` is reserved for the window function
  of that name, and Doctrine quotes reserved identifiers in generated DDL, so the table was created without
  complaint on every engine — but the statements the allocator writes by hand carry the column as bare SQL text,
  and the first record to ask for a number took the whole profile install down with a parse error before PHPUnit
  even started. Renaming it `current_value` keeps the property every other column in the schema already had.
  (`38e431c`)
- **One held outbox row stalled every other dispatcher.** The claim selects with `FOR UPDATE SKIP LOCKED`
  precisely so two dispatchers route around each other, but the quarantine pass running just before it in the
  same transaction compared two columns, which the claim index cannot serve, so the engine scanned the table and
  locked what it read. The pass now gathers its candidates under the same `SKIP LOCKED` clause, bounded, and
  updates them by primary key. This was invisible until the store was tested somewhere other than an in-memory
  database, where the lock clause compiles to an empty string and every assertion about arbitration is vacuously
  true. (`6e0d2e2`)
- **A duplicate scheduler occurrence crashed the scheduler on PostgreSQL.** Two schedulers reaching the same
  occurrence is designed for — the unique index refuses the second insert, the refusal is swallowed, and the
  schedule still advances — and that is what happens on the other engines. On PostgreSQL a constraint violation
  marks the whole transaction aborted, so the swallowed duplicate left the schedule advance and every remaining
  schedule in that pass unable to run, the pass died, and the schedule re-emitted the same occurrence forever.
  The insert now runs inside a savepoint and the swallow rolls back only the refused insert. (`8bb8cc4`)
- **A replica-lease heartbeat inside the caller's transaction produced intermittent failures on MariaDB.** The
  lease row is shared by every process in a container, and the consumer dispatcher re-asserted the runtime
  generation inside the transaction that also ran the handler and settled the inbox — but that transaction's
  read view was already open, and snapshot isolation refuses a write against a row another transaction committed
  after the view opened. A peer renewing the very same lease microseconds earlier therefore turned the
  consumer's own bookkeeping into a read-conflict error, the event went back to pending behind a retry delay,
  and the drain loop exhausted its budget. The lease write is now suppressed while a caller's transaction is
  open — it was wrong inside regardless, because a rolled-back handler rolled the renewal back with it — and the
  row is claimed with one upsert per driver rather than an update, an insert and an update. (`155a8a9`)
- **Four of five demonstration staff roles hit a raw 403 immediately after signing in.** Sign-in always
  redirected to the dashboard, but the dashboard route demanded `content.read`. The route and the navigation
  entry now require only `administrator.access`, the handler skips the content reads for an actor without
  `content.read` and degrades to its permission-reduced state, and a denied browser navigation is answered with
  the themed access-denied page — the shell, the actor's own capability-filtered navigation, a notice naming the
  missing capability, and a way back — while mutations, API accepts and callers without a renderer keep the
  problem document unchanged. (`f274e35`)
- **The business-security screen was unreachable for the entire demonstration cast,** because no role held the
  capability. Declared roles are now reconciled additively on every provisioning run, so a manifest revision can
  reach an already provisioned deployment, while grants an operator added by hand are left untouched.
  (`d3fd648`)
- **The Export control on generated list views promised an artifact that never existed** — it was a plain link
  that re-rendered the same list under the export disclosure policy, with no job, no artifact and no download
  behind it. (`46c6b6c`)
- **Portal screenshots were reproducible only on the machine that generated them,** because the portal left
  `code` on the generic `monospace` keyword while the administrator surface named a concrete family: a record
  identifier renders inline beside proportional text, so the line box grows to the union of both leaded boxes
  and the page height moved by two pixels between hosts. (`980cc79`)
- **Installing the append-only audit triggers made the platform uninstallable on managed MySQL services.** The
  privilege they need is withheld by default when binary logging is on, the exception escaped the migration, and
  `database:migrate` died. Trigger installation now reports a refusal as a state and the migration carries on,
  with the refusal recognised on driver error codes and SQLSTATEs rather than on message text or exception class
  — which is not a usable signal here, since one driver maps three refusals of a single kind onto two unrelated
  types. The degraded state is observed from the server's own catalog on every verification pass rather than
  remembered, so the answer stays true after a dump is restored onto a server that never accepted the triggers,
  after a DBA grants the missing privilege, and after someone drops them. (`ae4b92b`)
- **An audit tamper probe left its own tampering behind.** The harness proves refusal by performing the real
  update or delete and reporting whether it threw; on an unguarded server the statement succeeds, and the probe
  left it that way, so one probe mutated a row permanently and every later test in the class failed with a
  digest mismatch it had no part in. Both probes now put back what they wrote. (`f78bc3c`)
- **The step-up purpose digest committed the submitted plaintext password.** The purpose is a canonical digest
  of the change set, stored on the proof row and repeated in audit metadata, and the user-creation form's
  password was folded into it; every credential-bearing field is now stripped before the digest is taken, which
  keeps the payload binding and drops the offline-guessable commitment. (`4bc5c74`)
- **Three defects the failure drills exposed, fixed rather than accommodated.** The Redis boundary let the
  driver's own exception escape a wrapper whose every documented failure is a `RuntimeException`, and let a dead
  server turn a readiness question into a raised error instead of a not-ready answer. The settings cache turned
  its own outage into a failed public read, which is unnecessary when SQL is the source of truth; it now
  degrades and records why, while the sign-in budget keeps failing closed, because that asymmetry is the control
  rather than an inconsistency. The media and export write paths emitted a raw PHP diagnostic beside the typed
  refusal they already reported properly, one line per refused write on an unwritable volume. (`f8b856e`)
- **A severed database session does not crash a worker,** which the recovery posture had assumed it did: the
  driver converts the loss, closes the connection, and the next statement opens a new one, so the attempt is
  recorded on a fresh session and the process drains cleanly. Crash-to-exit is what happens when the server is
  gone rather than merely disturbed. Both are now stated and both are tested. (`f8b856e`)
- **The exported package overflowed the catalog's profile envelope on a well-populated database,** because the
  shared integration database accumulates published definitions from the whole suite; round-trip validation now
  writes a package filtered to the test's own definition and records. (`bd3ffa5`)
- **The branded theme broke the public menu the moment it was activated.** Its stylesheet flattened every nested
  list into the header's flex row so submenus rendered permanently expanded, it offered no small-viewport
  toggle, and it reused host-owned shell class names while the mandatory host stylesheet still loads — handing
  the shell over to rules the theme never wrote. The theme keeps rendering the host menu tree through the shared
  navigation macro so nested items, canonical hrefs and current state stay in lockstep with the platform, and
  every shell class now carries a theme prefix. (`fb6b41f`)
- **Seeding the six layout types silently changed the content editor's default type,** because the unqualified
  new-content route built its form from the first handle alphabetically; the core page type is now preferred
  whenever it exists and no type was requested. (`d10dc8b`)
- **The master-detail embed dropped the new per-item template and colour-scheme selects,** because the embed
  strips outer context and the new variables were not named in its context map. The sticky catalog aside also
  gained keyboard scrolling, and the subtle text token darkened to clear the contrast ratio on striped rows.
  (`80d20d6`)
- **Reference terms were set in small tinted pills that made section headings genuinely hard to read;** they now
  render at heading scale in full-contrast ink. (`7d6ef3f`)
- **`@throws` accuracy across the business modules turned up two real defects.** Entries had been invented — one
  exception was claimed seventeen times in a file whose code never names it — and one entry was incomplete,
  since the query compiler propagated an exception it did not declare, which made its caller's catch read as
  dead code. Fifty-three level-max errors surfaced on the first run of the pass. (`7a035e2`)
- **The integration suite was not re-runnable against a reused database.** Three consecutive runs now report
  identical results; previously the second run collapsed from 1,318 to 280 assertions as roughly sixty-three
  tests silently stopped verifying anything. (`115cc3c`)
- **The business-record idempotency ledger grew unbounded,** because its purger was container-registered with no
  caller; it is now an installation-global job with a seeded schedule, mirroring the core ledger. (`115cc3c`)
- **The access-control service bypassed the user aggregate's status lifecycle** by writing the status directly.
  (`115cc3c`)
- **Interface tabs lost their state after a cancelled submission,** and public navigation stopped working
  entirely without JavaScript. (`89cf4b8`, `e4df5c9`, `9ed6b2b`)
- **Schema operation states rendered outside their embed scope,** and export status controls were not
  touch-safe. (`15108eb`, `7d30ed6`)
- **Residual browser accessibility and lifecycle regressions,** including authenticator enrollment rendering,
  responsive access management, zoomed content-model controls, zoomed layout checks and the guest portal's
  layout and security icon. (`b9280fb`, `3c565b4`, `dae61ff`, `fd3cc06`, `ea55b99`, `7984fee`, `ee6d8b6`,
  `1837af2`)
- **Idempotent replay returned a reconstructed body rather than the exact original,** and a crashed job or
  in-flight idempotent request had no recovery path. (`25446d7`, `4da98bf`)
- **Requests from trusted proxies were not normalised,** so client address and scheme could be read from the
  wrong hop. (`3d8eab3`)
- **Queued export policy was not rehydrated when the job ran,** so a queued export could be generated under a
  policy snapshot it no longer held. (`b76ca76`)
- **Export authority failures were indistinguishable from ordinary failures,** and contribution and export
  runtime fences did not hold across a generation change. (`0969ae9`, `f34d27b`)
- **Production route caches were shared between installations in one image.** (`79431eb`)
- **Contributed page failures were swallowed by deployment acceptance instead of being captured.** (`412160e`)
- **Cross-engine portability defects on freshly created databases** — collations not copied from the site
  identifier column in the security migration, content identifier collations, generated lifecycle table quoting,
  non-portable schema indexes, a deprecated schema API, non-portable migration joins, unbounded DBAL
  affected-row results, non-portable identity timestamps, and worker heartbeats that were not MariaDB-safe.
  (`8dda466`, `370d594`, `0137f2c`, `b84e4e1`, `287744f`, `8a23094`, `a546aba`, `9531303`, `34a6423`,
  `186702d`, `35e1b86`)
- **Runtime key-ring loading refused legitimate configurations,** rejecting an empty key-ring object and losing
  the ring's map type; key validation now happens where the ring is built. (`e585f7e`, `867c6d4`, `c62a9ef`,
  `427cd17`)
- **The PHP-FPM worker lifecycle did not survive an unprivileged start-up,** and the unprivileged start-up
  itself needed a writable path it had been denied. (`bf95c4c`, `d6016e9`)
- **Permanent job failures were instantiated incorrectly,** and empty JSON objects were refused at console
  boundaries. (`ba9df5d`, `6d22830`)
- **Runtime concurrency tests shared one connection,** which made their arbitration assertions meaningless.
  (`1230ba3`)
- **A PHPUnit 13 addition made a private test helper a fatal error at class-load time,** taking the whole
  integration suite down before a single test ran, on every branch. (`1726ee1`)

### Security

- **A legal entity's books cannot be jointly owned, by construction rather than by discipline.** There is no
  setting, environment variable, manifest key or contribution that makes an accounting document, a ledger or
  a pay run shareable; the refusal is a property of the type system and, where the engine supports it, of the
  schema. A group-scoped ownership row for any of them cannot be assembled, so it never reaches storage to be
  rejected there. (`e46104b`)
- **Reading across a group buys no write across it.** The consolidated reporting capability is bound to the
  group resource alone. A caller holding it and nothing else is refused every write on a group-owned record
  and on another business's records alike, and the suite asserts both. Group membership also does not pool
  grants: a caller working in one member site cannot exercise a grant scoped at another member site, so
  widening a record's owner never widens anybody's authority. (`e46104b`)

- **Production refuses to boot with unsigned local extensions permitted.** Pairing the production environment
  with the unsigned-local flag now throws at configuration time, beside the existing HTTPS and
  secret-independence rules, with a message naming the commands to register and use a trust key instead.
  Development and testing keep the unsigned local workflow unchanged. (`5bf08c2`)
- **The conformance-admission mode cannot become the bypass the signature flag was:** production refuses the
  off mode outright, and only two findings block admission — PHP that does not parse, and a manifest naming a
  class, asset or template the package does not carry — both of which describe a package already broken in a way
  that would otherwise surface as a fatal error on a live request. (`5bf08c2`)
- **Revocation-feed integrity fails closed while availability does not.** A served list that fails verification,
  freshness or the sequence check is refused, audited and buried as a permanent failure; an unreachable origin
  leaves the last applied list in force and reports staleness loudly, because failing closed there would let a
  vendor outage act as a remote kill switch over installations the issuer does not run. (`5bf08c2`)
- **Content-Security-Policy splits `style-src`,** so an injected style element is refused outright while the
  style attribute three shipped templates use stays admitted — which removes the exfiltration class and leaves a
  named, narrower residual. (`5bf08c2`)
- **Vulnerability policy gained CVSS-banded remediation windows and a 90-day disclosure ceiling,** grouped
  weekly dependency updates with security updates ungrouped, and five repository-specific secret-scanning rules
  over the retained default ruleset, each verified by constructing a synthetic positive, confirming it fired,
  and removing it. (`5bf08c2`)
- **Append-only enforcement on the audit trail stops being a convention.** Per-driver triggers refuse `UPDATE`
  outright and refuse `DELETE` unless the session has opened the retention window through the sanctioned
  removal path. The control cannot stop an account that may drop triggers, which is why the operations runbook
  pairs it with least-privilege database accounts and states the exact grant, what running without it costs,
  and how to close the gap afterwards. (`05ff831`, `1e8bc12`, `ae4b92b`)
- **Audit-trail properties are documented honestly, including the two an operator would otherwise get wrong:**
  position gaps are not evidence of tampering, because a rolled-back transaction consumes a value; and the
  triggers stop mistakes and casual tampering but not an account that may drop them. Incident response gained
  the two commands it was missing, so preserving audit evidence no longer means raw database access.
  (`1e8bc12`)
- **Record secrets no longer depend on `APP_SECRET`,** which had made both secrets un-rotatable in practice:
  rotating the application secret stranded every stored envelope, and a single hard-coded key could not be
  replaced without making everything it sealed unreadable. (`a669846`)
- **Key material keeps its bytes private,** marks its constructor parameter sensitive so a stack trace redacts
  it, and redacts itself from debug output; a sweep proves no message or stack trace carries the plaintext or
  the key in raw, hex or base64 spelling. (`a669846`)
- **There is deliberately no authorized decrypt path for a stored record secret, and that is the control.**
  Because no reveal exists, no compromise of a session, token, delegation or field-visibility rule can produce
  one. What a reveal would have to carry, if a real integration ever needs stored credentials back, is written
  down so the question is answered in advance rather than argued about under pressure. (`9c4d744`)
- **A record-key compromise procedure that opens with the uncomfortable part:** re-encryption stops future reads
  under the old key, it does not undo a copy already taken, so the credentials the secret fields hold have to be
  rotated on their own systems too — then the order that matters, ending with retiring the old key last, because
  revision history still names it. (`9c4d744`)
- **The re-keying pass discloses nothing about which records moved.** Plaintext exists between one decrypt and
  the encrypt on the same line and nowhere else; the audit entry carries counts and key names only, because a
  rotation is a property of the installation and naming a particular record's secret would disclose something
  the record itself protects. (`8706736`)
- **Password credentials could be written once and never again,** so a compromised or ageing password had no
  retirement path short of suspending the account and the platform's own credential-change invalidation could
  never fire for passwords. A lost authenticator with spent recovery codes was permanent, because every
  operation that could have reset it was gated on the step-up the holder could no longer pass. (`4bc5c74`)
- **One security-epoch advance now retires API tokens, portal sessions, administrator sessions and every
  outstanding step-up proof together,** rather than leaving a live administrator cookie outliving the
  break-glass revocation that killed the same person's tokens. (`4bc5c74`)
- **Advisories taken as they were published.** A model-context SDK advisory — an unbounded server-sent-event
  buffer that lets a hostile or merely broken endpoint exhaust the process — was closed by taking the fixed
  release with no constraint widened. Two frontend advisories, an indefinite loop in an identifier generator
  and a regular-expression denial of service in a schema validator, were closed by lockfile moves inside ranges
  already declared, with the rebuilt bundles reproducing byte for byte as the evidence nothing user-visible
  moved. (`d57b680`, `b288711`, `a63ce9c`)
- **Every workflow action is pinned to a verified release commit.** One proposed pin was the tip of an
  upstream repository's main branch and carried no tag at all, as did the pin it replaced — a branch tip is not
  a release and buys none of the immutability the pinning is for. Each pin now carries the tag it resolves to
  in a comment, so the next reviewer can check the claim without leaving the diff, and each action's inputs were
  read at that commit to confirm the workflows' keys and outputs still exist there. (`05fe279`)
- **Authorization is enforced in the application use cases,** not at the delivery edge, so every surface
  inherits one deny-by-default decision. (`0b0fbdf`, `5020325`, `d16675d`, `96e0eef`, `4258406`)
- **Protected portal extension roots are canonicalised** before they are resolved. (`a1f5345`)
- **Export grants are credential-scoped,** so an artifact cannot be downloaded under authority the requester
  never held. (`463a4f5`)
- **Canonical-host protection on the machine surface** is preserved and exercised against acceptance hosts
  rather than assumed. (`9bd81e7`, `1ca8b24`, `e0bf660`)
- **Containers run unprivileged,** including the development image, the Redis entrypoint, the PHP-FPM start-up
  path and deployment secret creation. (`8220dc3`, `7b19591`, `b4ac2f0`, `59434da`, `010d920`, `6549cc3`,
  `7f9dcc8`)
- **Access-token bootstrap is secure by construction,** and acceptance binds tokens to an explicit site and
  exercises closed token contexts and delegation ceilings. (`6549cc3`, `aaef585`, `9fb67ac`, `dd03b2c`,
  `620c4d1`, `0b0e1c8`, `4ddcd6c`)
- **Security headers and trusted-host matching were the first two things the clean baseline shipped.**
  (`b40d2af`)

### Deprecated

- **The `APP_SECRET`-derived record encryption key identified as `application-secret-v1`.** Configuring
  nothing keeps it active with its original derivation reproduced byte for byte, because those bytes are in
  production databases and are not ours to change; configuring dedicated key material makes it retired rather
  than absent, and `RECORD_ENCRYPTION_LEGACY_SECRET` pins the old derivation to the outgoing application
  secret so an installation can finish the move. A test asserts the derivation literally rather than through
  the class, so if it ever needs changing the failure says what it really means. (`a669846`, `8706736`)
- **Per-entry ceiling reads of archive contents, and hand-maintained autoload lists in the drill entry
  points.** Both patterns are retired in favour of streamed reads bounded against the bytes that actually
  arrive, and a registered loader mapping; an architecture assertion refuses a hand-maintained list that grows
  back. (`cfaf840`, `26a7b39`)

### Removed

- **The inherited 1.x-era front controllers, MVC libraries and installation tree** — 155 files — replaced by a
  clean 2.0 baseline whose first commits are security headers, trusted-host matching, typed configuration and an
  architecture policy check. (`b40d2af`, `b20c5b5`)
- **Dead public API on the trust store and the extension platform.** Four uncalled trust-store entry points, an
  unfenced Redis lock pair superseded by leases, two unreachable domain transitions, the `ExtensionLifecycle`
  and `ExtensionRegistry` interfaces which had no implementers, and an unwired administrator boundary handler.
  A test-only authorizer that lived in `src/` moved to the test support namespace. The one operational need
  behind a removed wrapper became an explicit `--repair` flag on the runtime materialisation command.
  (`115cc3c`)
- **The record-detail export link,** because the pipeline exports record sets: the honest list-level control is
  one step away, and a detail-level link would keep promising a single-record export the pipeline does not
  provide. (`46c6b6c`)
- **Session narrative from the user documentation,** which described how the work was done rather than what the
  product does. (`115cc3c`)
- **Abandoned web-root artifacts** left over from the inherited layout. (`b20c5b5`)

---

## What is not here

This file records completed work. Open objectives, gates, work packages and findings live in
[`docs/roadmap/`](docs/roadmap/README.md), and the executed evidence of the eight production-qualification waves
is retained in [`docs/qualification/gap-matrix.md`](docs/qualification/gap-matrix.md) as a historical record
rather than a plan.
