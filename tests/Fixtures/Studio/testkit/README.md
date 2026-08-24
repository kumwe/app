# `@kumwe/studio-testkit`

Status: pre-Gate-A foundation alpha. Its conformance helpers do not by themselves constitute gate evidence.

The current alpha exports three builders—`createBlueprintFixture`, `defineTestBlock`, and
`createStudioConfigurationFixture`—plus `assertBlueprintConforms` and its structured
`StudioConformanceError`. The assertion checks a Blueprint against supplied block definitions through
the current core validator. It does not yet prove host, plugin, theme, preview, media, accessibility,
security, lifecycle, or cross-language conformance.

Package subpaths under `@kumwe/studio-testkit/fixtures/<filename>` contain byte-identical copies of the
canonical valid examples, including separate MediaAsset and persisted MediaReference examples. There
is no aggregate runtime `fixtures` export. The helpers do not depend on Vitest or another test runner,
so consumers may call the current assertion from any JavaScript test environment.

`fixtures/authoring-message-catalog.en.json` is the byte-identical canonical English shell catalog.
It lets hosts validate localization imports and parameter declarations without importing the Lit
runtime. Its schema ships with `@kumwe/studio-protocol`.

## Host testbed

`createTestbedHost(options)` builds a deterministic, fully in-memory `HostAdapter` reference host
for conformance and failure testing. Every standard port is implemented against
seeded fixtures (`documents`, `resources`, `mediaAssets`, `messages`, `permissions`, and an
optional asynchronous preview `render` callback). Each operation first validates connectivity and the
`HostRequestContext`, including exact operation-capability matching, wire protocol, and current session
generation. Every failure rejects with `TestbedHostError`, whose `error` document satisfies
`isHostPortError`. `TestbedHostError` extends the protocol's public `HostPortFailure`, so the same
rejection satisfies `isHostPortFailure` without consumers depending on the testbed-specific class.
Stale-generation rejection is `invalid-request` with the exact
`studio.host/stale-session-generation` diagnostic used for whole-handle invalidation; unrelated
invalid requests do not carry that meaning. `not-found` messages never disclose which identifiers
exist. Seed artifact kinds, revisions, and the optional initial `sessionGeneration` are retained
exactly; subsequent revisions advance as deterministic counters (`<id>-r<n>`).
Optimistic-concurrency conflicts return the safe current revision without changing stored state, and
search/media pagination uses opaque cursors. The returned `controls` drive
failure scenarios: `disconnect()`/`reconnect()` for retryable `unavailable` outages,
`failNext(port, operation, category)` for exactly one injected failure (retryable only for
`unavailable` and `rate-limited`), and `setPermissions(...)`, which replaces the permission list
and bumps the session generation so stale contexts reject as `invalid-request`. `revisionOf(id)`,
the `sessionGeneration` getter, and the recorded `telemetryEvents` support assertions. The logical
`advanceClock()` control and declared fixed-window policies make rate-limit tests deterministic.
Idempotency records use the published scope/preimage, coalesce matching accepted mutations, reject
changed intent, and remove failed attempts. Preview render controls expose pending and delivered
outcomes so cancellation proves that late output is discarded. An injected renderer must return the
originating draft digest and render `requestId`; an uncorrelated result fails as non-retryable
`internal` and is never recorded as delivered.
`createHostRequestContextFixture` builds request envelopes; callers provide the exact operation ID for
strict hosts. The `allowTestOperationId` option permits only the fixture wildcard for broad unit drills,
defaults off, and must never be enabled for conformance replay. The testbed uses no wall clock,
randomness, or timers, so runs are fully reproducible.

## Host conformance corpus

`@kumwe/studio-testkit/vectors/host/<filename>` publishes the executable assertion set for
`studio.profile/host-baseline`. Each vector is language-neutral JSON conforming to
`host-vector.schema.json`: it fixes the host state to seed (`given`), the request envelope and
argument to send (`context`, `argument`), and the required outcome (`expect`) — an accepted result
with its revision behaviour, or one category of the closed error taxonomy with its retry
classification and non-disclosure obligations. Every precondition is a condition a real host
reproduces, never a test double, so an adapter written in any language replays the corpus in its own
test suite without executing Studio code. The reference host's own claim against the profile is
`test/host-vectors.test.ts`. The profile records the obligations it does not yet assert; consult
`docs/contracts/conformance-profiles.md` before treating a green replay as complete coverage.

`@kumwe/studio-testkit/vectors/host-sequence/<filename>` publishes the additive assertion set for
`studio.profile/host-baseline-v2`. The bounded sequence shape expresses pending and settled operations,
fixed-window policies, explicit clock advances and renderer completions, and final state. Nine vectors
cover in-flight/completed replay, changed argument/context refusal, canonical numeric intent,
resource-scope separation, wrong-operation refusal, failed-attempt retry after a window reset, matching
preview cancellation with late-result discard, and cross-context cancellation isolation. The
TypeScript replay is `test/host-sequence-vectors.test.ts`; other runtimes consume the same JSON and
schema directly. The exact assertion list and deliberately recorded limits live in
`docs/contracts/conformance-profiles.md`.

The host corpus includes `model.get` and `model.list` exchanges over seeded content models. The testbed
resolves exact ID/version/revision coordinates and lists detached projections in deterministic coordinate
order. The injected HTTP adapter maps the same operations to `POST /ports/model/get` and
`POST /ports/model/list`.

`@kumwe/studio-testkit/vectors/canonical/<filename>` publishes the canonical serialization corpus:
each vector carries a bounded value and either the exact canonical string with the SRI-style digest of
its UTF-8 bytes, or the stable reason the canonical form refuses it. Its expectations were produced by
an independent canonicalizer rather than recorded from the reference implementation, so replaying it
is a cross-implementation check.

`@kumwe/studio-testkit/vectors/preview/<filename>` publishes the executable assertion set for
`studio.profile/preview-identity-v1`. Each complete Blueprint fixes the lowercase SHA-256 digest of
its canonical UTF-8 bytes, the artifact/revision/digest render tuple with a unique attempt ID, and the
exact marker preorder plus one-to-one marker map. The reference replay is
`test/preview-vectors.test.ts`; another runtime hashes and traverses the same JSON without executing
Studio code.

`@kumwe/studio-testkit/vectors/schema-profile/<filename>` publishes the executable assertion set for
`studio.profile/schema-property`. Accepted candidates carry instance verdicts and the first stable
keyword/pointer diagnostic; rejected candidates carry the closed admission code and schema pointer.
`runSchemaProfileVector(vector)` replays one vector through the TypeScript reference without consulting
its expected verdicts or diagnostics. Other runtimes consume the same JSON directly and do not execute
testkit code. Boundary vectors identify their published limit, exact value, and side of the ceiling;
the corpus contains exactly one at-limit and one exact-plus-one case for every advertised limit. Its
acyclic-reference fan-out case must complete without expanding the reference DAG exponentially. The
TypeScript reference exposes the full ordered set of distinct failures: repeated branches do not
duplicate an otherwise identical keyword, instance pointer, and message diagnostic.
Competing-failure vectors also pin one admission order across root invariants, local-reference
resolution, recursion, and structural keyword checks.

`@kumwe/studio-testkit/vectors/binding-projection/<filename>` publishes
`studio.profile/binding-projection-v1`. Each vector carries a complete Blueprint, authorized content model,
block definitions and exact normalized candidate/control/status/diagnostic output.
`runBindingProjectionVector(vector)` replays one vector without reading its expected result; another runtime
consumes the same JSON directly. The runner and reference projection never mutate vector input.

`@kumwe/studio-testkit/corpus-manifest.json` carries the sha256 digest of every file in the published
corpus, grouped by the directory it ships in. A host that vendors the corpus verifies its copy against
this manifest, so a stale or altered fixture is detected before it silently changes what a conformance
claim means. The schema manifest in `@kumwe/studio-protocol` covers the schemas; this covers everything
replayed against them.

`@kumwe/studio-testkit/studio-release.json` is byte-identical to the workspace and protocol copies. A
host can vendor this record with the corpus, verify `corpusManifestDigest`, and resolve the exact seven
package versions named by one Studio release coordinate. An empty `claimedProfiles` array is deliberate:
repository tests and declared targets are not evidence-backed release claims.

Gate A requires valid and invalid fixture corpora plus runner-neutral assertions for block, theme,
plugin, host-port, command, preview, media, compatibility, migration, lifecycle, security,
accessibility, localization, and TypeScript/Dart equivalence. The host-port and narrow preview-identity
corpora are executable increments; the remaining profiles are target deliverables, and this foundation
alpha must not be cited as gate evidence.
