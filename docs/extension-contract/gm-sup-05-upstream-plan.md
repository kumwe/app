# GM-SUP-05: coordinated remote execution contract

This is an implementation proposal against released `kumwe/extension-sdk:0.3.3`, not an implemented API
or an isolation claim. `point-five-beta-qualification.md` inventories the thirteen current live-object
bindings and the ambient authority that remains. The external record adapter can ship independently;
it does not change the trust level of installed PHP.

## Smallest useful upstream increment

Start with a signed **remote-only** component that contributes inert definitions and asynchronous
integration consumers/jobs. Reject every unsupported executable binding at admission. Do not load its
PHP provider, autoload map, migration classes or bootstrap hooks in the host. This gives one enforceable
isolation tier without pretending that synchronous listeners, live projection writers or HTTP handler
factories already have remote equivalents. Full thirteen-SPI equivalence is a later explicit contract;
unsupported features must remain visibly unsupported throughout.

The first contract must specify:

- A new manifest generation with a remote entrypoint, immutable artifact digest, protocol version,
  declared contribution kinds, and explicit filesystem, network and database grants. The signed digest
  covers both these declarations and the executable subject.
- A host-issued short-lived invocation credential, bound to installation, owner, package checksum,
  generation, operation, contribution, site, audience and deadline. Never serialize an
  `ExecutionContext`, pass database credentials, or accept a principal asserted by the extension.
- Bounded request/result/error envelopes; integer byte/depth/collection limits; cancellation and timeout;
  schema versions; idempotency and replay identities; error classification and safe diagnostic fields.
  Existing package value codecs remain canonical for decimals, money, quantities and dates.
- A finite authenticated host operation set with independent authorization and field disclosure on every
  call. For the first increment use declarative portable schema plans rather than raw PHP migration code.
  Worker retries retain one operation identity and owner generation; revocation terminates admission and
  rejects stale results. No promise of exactly-once network delivery.
- A reference worker and conformance fixtures that exercise actual credential expiry, wrong owner/site,
  replay, payload limits, timeout, crash and generation changes. A PHP subprocess alone is insufficient:
  the shipped launcher must enforce separate identity, filesystem mounts, network allow rules and resource
  limits, with denied-access probes demonstrating those bounds.

## Concrete ownership and source changes

Paths in the SDK column are relative to the **upstream SDK repository**, as inspected in the installed
release. They are not instructions to edit App's `vendor/` copy. New names below are proposals.

| Owner | Existing source paths to extend | Change and acceptance |
| --- | --- | --- |
| SDK manifest | `src/Manifest/ExtensionManifest.php`, `ExtensionManifestGrammar.php`, `ManifestContributions.php`, `ManifestContributionGraphValidator.php` | Admit a new remote-only grammar; reject mixed remote/trusted bootstrap authority and unsupported kinds. Existing schemas 1–6 remain byte-compatible. |
| SDK binding contract | `src/Spi/Binding/ExecutableBindingKind.php`, `ExecutableBindingRequirements.php`, `ExtensionBindingRegistrar.php`, `ExtensionBindingProvider.php` | Add remote declaration/requirement types alongside the frozen local registrar. Do not alter the 13 existing PHP signatures or serialize their implementations. |
| SDK protocol | Proposed `src/Spi/Remote/` | Own immutable envelope/peer/invocation/grant DTOs, protocol and codec versions, bounded validation and failure semantics. Keep generic business value types with their released owning packages. |
| SDK authoring | `src/Toolchain/ComponentScaffolder.php`, `ScaffoldRequest.php`, `StaticConformanceRunner.php`, `LifecycleConformanceAdapter.php`, `LifecycleConformanceRunner.php`; `resources/extension-scaffold/` | Add an explicit remote-only scaffold, signed subject checks and executable failure probes. Existing local scaffold remains available and labelled trusted. |
| SDK compatibility and release | `resources/fixtures/generations/`, `resources/fixtures/pins/`; package public API/capability/service manifests and release workflow | Add successor fixtures and new pins without rewriting old fixture bytes. Run the producer's full compatibility/native/dependent-package checks and publish one immutable reviewed release. |
| App admission and composition | `src/Extension/Infrastructure/DoctrineExtensionManager.php`, `src/Extension/Contribution/OwnedExtensionBindingRegistrar.php`, `ExtensionContributionRegistrySet.php`, `src/Extension/Runtime/ExtensionRuntimeMapCompiler.php`, `src/Kernel/ContainerFactory.php` | Recognize remote-only packages only after adopting the published SDK. Never invoke their local provider. Pin proxy/launcher identity into the trusted generation; fail closed on absent launcher or unsupported declarations. Parent owns coordination of these App files. |
| App execution | `src/BusinessIntegration/Application/DurableOutboundAdapterDispatcher.php`, `src/Extension/Application/Migration/ExtensionMigrationRunner.php` and current contributed job/consumer dispatchers | Carry canonical durable work identities into bounded authenticated invocations; reconcile timeout/retry/revocation; approve and execute declarative schema without loading extension migration PHP. |
| Deployment runtime | New separately built launcher image/profile and App deployment packaging | Enforce the signed grant policy with an OS/container boundary. App secrets and database sockets are absent unless expressly mediated. Prove filesystem/network/database denial, process/resource termination and audit attribution. |

Verify exact dispatcher locations before implementation; the SDK producer and App host must agree on
whether a call is asynchronous, transactional or user-facing before adding it to the admitted operation set.

For the remaining synchronous SPIs, the producer must first define semantics for transaction-bound domain
listeners, bounded projection writer batches, authorization-bound custom actions and views, PSR HTTP
request/response bodies, field presentation/escaping, rate/unit provider provenance and contextual Studio
preview resources. These are distinct contracts, not one arbitrary remote method dispatcher. A deployment
must not advertise support for any of them until both its released DTOs and host adapter are qualified.

## Merge and immutable release sequence

1. Prepare an upstream SDK branch/PR containing the remote grammar, DTOs, scaffold and conformance tests.
   Review the proposed authority model independently. The maintainer merges that PR; App PR #152 cannot
   turn an unmerged SDK checkout into a released dependency.
2. Run the upstream release gates on the merged producer commit and publish a **new immutable SDK tag and
   package**, with its exact dependent package releases where public types changed. The current 0.3.3 tag
   and all frozen fixtures remain untouched. No version number is promised before producer review.
3. Adopt that published version through App's normal Composer lock update, then land the host/launcher
   adapters and the signed remote fixture against the exact released types. This is a separate coordinated
   App implementation, including the parent's composition-root and runtime-map review.
4. Qualify installed remote artifacts through upgrade, disable/reactivate, key rotation/revocation,
   crash/retry, restart, backup/restore and uninstall on MariaDB, MySQL and PostgreSQL. Publish the launcher
   artifact only through its reviewed release chain. Independent review decides whether the resulting
   supported isolation tier meets GM-SUP-05; the finding stays open before that evidence exists.

No producer merge, tag, publication, App dependency update or shipped isolation runtime is performed or
claimed by the current extension proof checkpoint.

## Verified canonical source coordinates and Studio target delta

These immutable upstream files were fetched through the GitHub connector on 2026-09-28 and match the
installed release. SDK 0.3.3 is commit `3d91050261bc26a5d89966250f88b11705b4923b`; Producer 0.3.0 is commit
`65d0a10ce39954738f091ec4b5e2626768053c8d`.

- [SDK executable registrar](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/src/Spi/Binding/ExtensionBindingRegistrar.php): all thirteen bindings take live PHP objects. There is **no canonical out-of-process invocation SPI** in this release.
- [SDK outbound transport](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/src/Spi/BusinessIntegration/Application/IntegrationEventTransport.php): the nearest existing external seam takes a webhook declaration and durable event. Its implementation still runs locally; it supplies no authenticated remote worker session or grant protocol.
- [SDK migration SPI](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/src/Spi/Migration/ExtensionMigration.php): both directions receive a live Doctrine connection. A remote-only tier must reject these local migrations and use an explicitly released alternative.
- [Frozen Studio preview renderer](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/src/Spi/Studio/Application/Preview/StudioPreviewBlockRenderer.php) and [v1 signature pin](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/resources/fixtures/pins/studio-preview-block-renderer-v1.json): the third argument is a semantic viewport string. No argument communicates the public/preview target.
- [SDK safe fragment](https://github.com/kumwe/extension-sdk/blob/3d91050261bc26a5d89966250f88b11705b4923b/src/Spi/Studio/Application/Preview/StudioPreviewBlockFragment.php): the existing allowlist already admits both a preview-width layout projection and all three public responsive projections. The missing part is explicit target input to the contributed executable, not permission to emit arbitrary HTML.
- [Producer rendering authority](https://github.com/kumwe/producer/blob/65d0a10ce39954738f091ec4b5e2626768053c8d/src/Render/RenderContext.php) and [block renderer port](https://github.com/kumwe/producer/blob/65d0a10ce39954738f091ec4b5e2626768053c8d/src/Render/BlockRenderer.php): the host already chooses marker inventory and published/fallback policy. These types need not change merely to carry an explicit target from App into the contributed SDK renderer.

The smallest **Studio-only** upstream change is separate from remote execution: add a successor
SDK rendering contract with a closed public/preview target and a nullable preview viewport, plus an
explicit new binding declaration/registrar capability. A public target has no selected viewport; a
preview target requires one of compact/medium/expanded. Preserve the frozen v1 signature and fixture.
Do not add a magic `public` viewport value or silently treat public output as expanded preview. Keep
`StudioPreviewBlockFragment`'s bounded, escaped presentation contract. Add the target-aware fixture and
public/preview conformance cases, including all-width responsive output and hidden binding disclosure.

App currently adapts v1 through `src/Studio/Application/Rendering/FragmentStudioPreviewBlockRenderer.php`;
`StudioBlockRendererRuntime.php` passes `$viewport ?? 'expanded'`. That exact substitution loses the
public/preview distinction for a contributed v1 implementation. After the new immutable SDK release,
update these two adapters, `src/Extension/Contribution/OwnedExtensionBindingRegistrar.php`, and
`src/Extension/Runtime/TrustEnforcingStudioPreviewBlockRenderer.php` to dispatch the released successor
using the host's trusted target and retain the before/after generation fence. Preserve v1 preview
compatibility; public admission must follow an explicitly defined compatibility rule and must not claim
target-aware support for a v1 renderer. Extend `tests/Integration/Studio/ExtensionStudioPreviewRendererIntegrationTest.php`
with one signed block whose public and preview decisions are observably different.

This Studio-only fix requires a maintainer-merged SDK successor and its new immutable Composer release,
then App lock adoption and host tests. It does not, on the inspected interfaces, require a Producer
public API change, an npm Studio release, or the full remote runtime. If producer review decides to place
the target DTO in another owning package, release that package first and pin it in the SDK release;
do not invent an App-local public replacement. The GM-SUP-05 remote envelope must later carry the same
released target DTO when Studio is admitted into its supported remote binding set.
