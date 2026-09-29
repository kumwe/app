# Point 5 extension proof for Beta 1

Scope: P7-F and the executable adapter portion adjacent to GM-SUP-05 in PR #152. This is bounded Beta 1
qualification evidence. It is not stable Gate B, maintainer acceptance, or a claim that marketplace PHP
is isolated. The roadmap's six separately signed archetypes and complete three-engine artifact lifecycle
remain the governing P7-F contract.

## Reuse review

The installed releases are `kumwe/extension-sdk:0.3.3`, `kumwe/integration:0.2.4`,
`kumwe/business-surface-contract:0.1.4`, `kumwe/reporting:0.1.5`, and `kumwe/conversion:0.1.5`.
The capability-index digest inspected was
`ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826`.
SDK and Integration charters, README/host integration, public API/capability/service-map manifests,
scaffold templates and installed SPI source were inspected alongside the App adapters and existing proofs.

The SDK owns scaffolding, deterministic packages, signatures and conformance. App already owns trusted
admission, runtime materialization, host persistence, authentication and the REST boundary. The SDK's
`ComponentScaffolder`, `ScaffoldRequest`, `DeterministicPackageBuilder`, `StaticConformanceRunner`,
`PackageSigner` and `ProtectedSigningKeyReader` are consumed directly. No SDK source, frozen fixture,
public signature or App composition root is changed. No new portable App class is introduced; CGR035 is
not consumed. The external PHP example uses the published REST API and no App/package implementation.

## Existing evidence retained

| Obligation | Existing executable owner |
| --- | --- |
| Released SDK scaffold, package, sign, disabled install, host admission | `tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php` |
| Signed neutral business graph, five related types, workflow, computation and restricted fields | `examples/extensions/asset-inspection/`; `AssetInspectionCustomViewIntegrationTest`; `AssetInspectionExampleTest` |
| Administrator and portal through real session pipelines | Generated lifecycle test and asset-inspection deployment/browser acceptance |
| REST, CLI and MCP business behavior | `tools/asset-inspection-deployment-acceptance.sh`; `GeneratedBusinessAdapterParityTest`; `ExtensionInstallMachineEquivalenceIntegrationTest` |
| Durable outbox/inbox, scheduled job, projection, report and export checksum | `tests/Support/AssetInspectionDeploymentAcceptance.php` and the deployment acceptance script |
| Worker/web/scheduler restart, disable/reactivate, source snapshot and restored snapshot comparison | Asset-inspection script and `.github/workflows/deployment-acceptance.yml` |
| MariaDB, MySQL, PostgreSQL deployment qualification | Existing deployment acceptance matrix, including the restored asset-inspection snapshot |
| Content variants and contextual preview contributions | `ContributedContentTranslationIntegrationTest`; `ExtensionStudioPreviewRendererIntegrationTest` |
| All five delivery surfaces share policy, values, revision and audit semantics | `tests/Functional/BusinessSurface/GeneratedBusinessAdapterParityTest.php` |

These entries name executable coverage, not successful runs on this exact PR head. The existing asset
inspection package is a neutral baseline; it must not be relabelled as all six separately signed P7-F
archetypes. Thousand-line exact-value, relationship, mobile and catalogue journeys already have their own
fixtures and acceptance lanes; this change does not copy them into another demo product.

## Added proof

The generated lifecycle now builds **untouched** 1.0.0 and 1.0.1 SDK scaffolds on the required PHP 8.5
platform. The prior PHP-floor rewrite is removed. It inserts one extension-owned row, disables the first
release, begins signing-key rotation, proves the outgoing key cannot retire while that disabled release
still depends on it, installs the successor under the replacement key, finalizes rotation, reactivates,
and boots a fresh kernel. Owned routes/projection return; the same data survives upgrade, reactivation
and uninstall. Successful install/activate/disable/uninstall audit entries are asserted from durable state.
The test remains in the ordinary integration matrix and supports the existing twice-run idempotency gate.

`examples/extensions/asset-inspection/remote/record-adapter.php` is a separately deployed, bounded PHP
REST client. Its integration test uses the actual HTTP front controller and a real database with tokens
issued by the existing membership-aware `MachineSurfaceHarness`. The child receives no application
environment or autoloader. A short-lived credential can be constrained to read/update; wrong site,
wrong audience, invalid/revoked credentials and missing write capability are refused. A hidden secret
field stays absent. Repeating the exact original key/body/ETag has one effect; a distinct stale write
fails. Input/header injection, unexpected fields, response size and redirect cases fail explicitly.
This demonstrates an external integration pattern available today without extending a frozen SPI.

## Why GM-SUP-05 remains open

`ExtensionBindingProvider::bind(ExtensionBindingRegistrar, ExtensionContainer)` runs locally. All
13 methods on SDK 0.3.3's registrar require live PHP implementations:

| Registrar binding | Required released implementation |
| --- | --- |
| `fieldPresenter` | `Kumwe\BusinessSurface\Contract\Presentation\Field\FieldPresenter` |
| `moneyRateProvider` | `Kumwe\Conversion\Provider\MoneyRateProvider` |
| `unitConversionProvider` | `Kumwe\Conversion\Provider\UnitConversionProvider` |
| `customBusinessViewHandler` | `Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessViewHandler` |
| `customBusinessActionHandler` | `Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionHandler` |
| `administratorRoute` | `Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory` |
| `portalRoute` | `Kumwe\Extension\Spi\Binding\Http\PortalRouteHandlerFactory` |
| `domainListener` | `Kumwe\Extension\Spi\BusinessIntegration\Application\DomainEventHandler` |
| `eventConsumer` | `Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventHandler` |
| `jobHandler` | `Kumwe\Extension\Spi\Application\Automation\JobHandler` |
| `projection` | `Kumwe\Reporting\Contract\ProjectionBuilder` |
| `webhook` | `Kumwe\Extension\Spi\BusinessIntegration\Application\IntegrationEventTransport` |
| `studioPreviewRenderer` | `Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer` |

The closest existing seam is `IntegrationEventTransport::publish(WebhookContributionDefinition,
IntegrationEvent)`: a **trusted local** implementation may deliver to an authenticated external endpoint.
`RuntimeIntegrationEventTransport` and `DurableOutboundAdapterDispatcher` already own the host's durable
receipt, generation and retry fencing. Merely implementing this interface does not move its PHP object,
provider/bootstrap code, or other twelve bindings into an isolated process.

The current schema 1–6 manifest grammar has no remote execution tier, peer identity or granted OS
resource declaration. There is no SDK wire representation/session for those thirteen implementations,
no remote replacement for the provider's `ExtensionContainer`, and no remote migration protocol.
In particular the generated migration's `up(Doctrine\DBAL\Connection, ExtensionTableNames)` executes
locally with a live connection; proxying HTTP handlers would leave that authority untouched. Synchronous
listeners run inside the authoritative transaction; projections receive a writer capability; these need
explicit failure/transaction/cancellation semantics, not serialization of live PHP objects or contexts.

A complete route to closure requires:

1. A released SDK successor defining signed remote declarations, bounded request/result/error DTOs,
   authenticated peer and owner identities, protocol versions, and migration/transaction semantics.
2. Host adapters for each admitted remote binding, retaining authorization, data disclosure, generation,
   timeout and replay fencing without loading untrusted providers into the application process.
3. A shipped OS/container runtime with explicit filesystem, network and database grants, separate user
   identities, constrained secrets, and tests that ungranted access actually fails.
4. Qualification of signed remote packages through install, upgrade, revocation, crash/retry, backup and
   uninstall on all supported engines and the exact release artifacts.

This change delivers none of those unreleased APIs by imitation. Trusted installed PHP continues to have
ambient process authority, exactly as `docs/architecture/extensions.md` states. The external client is a
usable bounded integration example, not an isolation tier or a reason to close the finding.

## Checkpoint and continuing Point 5 scope

The full P7-F six-archetype contract remains in scope. The first checkpoint adds
`SignedWorkflowExtensionFixture` and four source model fragments copied from the existing P7-E browser
preparation. Five backend cases exercise signed content publication, thousand-line exact document
posting, relationship graph/disclosure, mobile assignment completion and catalogue payment/fulfilment
state. `examples/extensions/workflow-portfolio/README.md` gives the browser integration API. Existing
GUI journeys are retained; their fixture admission and handles still need conversion to these signed
owners. None of these backend assertions is described as a GUI run.

Still required after this checkpoint: the sixth multilingual content/business archetype; all remaining
primitives of the six P7-F profiles; upgrade/disable/reactivate/restart/data preservation for **each**
archive; reuse of the existing GUI journeys against those artifacts; and actual three-engine
backup/restore/platform-upgrade evidence. Generic scaffold and neutral asset proofs remain useful shared
checks, but do not fill those portfolio-specific obligations by themselves.

The concrete upstream work and maintainer merge/release sequence for GM-SUP-05 is in
`gm-sup-05-upstream-plan.md`. No SDK source is changed here and no new portable App class is introduced.

Local checkpoint validation on PHP 8.5.9, native engine 1.0.3 and MariaDB 11.8.8:

- `GeneratedExtensionLifecycleIntegrationTest`, `RemoteRecordAdapterIntegrationTest` and
  `SignedWorkflowPortfolioIntegrationTest`: **9 tests, 288 assertions**, twice consecutively on the same
  database without resetting between runs.
- `AssetInspectionExampleTest` and `GeneratedBusinessAdapterParityTest`: **6 tests, 358 assertions**.
- Targeted PHP_CodeSniffer and documentation-block checks pass; `git diff --check` passes.
- An earlier combined attempt reported `Refusing to replace a newer local runtime generation` in two
  `TestKernelFactory::create()` calls. The clean recheck and its second same-database pass both passed;
  the earlier result remains an unresolved intermittency observation for the parent's runtime review,
  not a waived gate or a claimed diagnosis. No runtime-map or composition-root code was changed.

The repeatable service command is the shared `../kumwe-setup/with-services.sh` wrapper with the immutable
parent schema installer and collation normalization, followed by the PHPUnit file list above twice. On a
new disposable database only, remove that checkout's old ignored runtime publication before bootstrap;
do not discard a production or shared installation's state. Local raw logs are in
`build/extension-proof/qualification-recheck.log`; they are not substitutes for authoritative CI.
