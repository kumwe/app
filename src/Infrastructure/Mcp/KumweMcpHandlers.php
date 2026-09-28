<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use Closure;
use InvalidArgumentException;
use JsonException;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\Content\Application\ContentModelNotFound;
use Kumwe\Content\Application\ContentNotFound;
use Kumwe\Content\Application\ContentRecord;
use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Extension\Application\ExtensionManager;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\ScopedAccessTokenVerifier;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\Access\Capability;
use Kumwe\Navigation\Application\MenuRecord;
use Kumwe\Navigation\Application\MenuItemRecord;
use Kumwe\App\Navigation\Application\NavigationService;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionService;
use Kumwe\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\Content\Domain\ContentTypeDefinition;
use Kumwe\Content\Workflow\Domain\WorkflowDefinition;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\BusinessSchema\Domain\SchemaPlan;
use Kumwe\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionOperation;
use Kumwe\App\Studio\Application\Composition\StudioContentComposition;
use Kumwe\App\Studio\Application\Composition\StudioContentCompositionService;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Media\Application\MediaAsset;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessageFormattingFailed;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Psr\Clock\ClockInterface;

/**
 * The whole MCP surface: one guarded method for every tool, resource and prompt the catalogue advertises.
 *
 * `KumweMcpServerFactory` binds each catalogue entry to a method here by name, so this class is where a
 * tool call from an MCP client becomes a call into the same application services the REST API and the
 * administrator use — never into a repository directly. Three things hold for every entry. The caller's
 * capability is checked before any work starts; a write is additionally pre-authorized against the one
 * resource it names, so the decision is recorded before anything happens; and the write then runs inside
 * `McpMutationGuard`, so a tool call that a flaky transport retries cannot apply twice. Failures raised by
 * the services below — not found, version conflict, validation — travel outward unchanged, which is what
 * keeps an agent's refusal identical to the equivalent REST call's.
 *
 * Instances are immutable and start out unbound, refusing everything. `forContext()` binds one request's
 * actor for the HTTP transport; `forCredential()` binds a retained token for the long-lived stdio
 * transport and re-proves it on every access. A bound copy never carries both.
 *
 * @since  2.0.0
 */
final readonly class KumweMcpHandlers
{
    /**
     * Wire the application services, the catalogue, and the two guards every tool runs behind.
     *
     * The container builds one unbound instance: neither identity argument is supplied, so every tool refuses
     * until `forContext()` or `forCredential()` hands back a bound copy.
     *
     * @param McpCapabilityCatalog $catalog Tools, resources and prompts this release exposes,
     *         as published by `discover()` and the capability resource.
     * @param ContentService $content Content entries behind the `kumwe_content_*` tools.
     * @param NavigationService $navigation Menus and menu items behind the `kumwe_menu_*` tools.
     * @param  AccessControlService                    $access            Users, roles, capabilities and token metadata.
     * @param SiteSettings $settings The site settings document, read and replaced whole.
     * @param  ExtensionManager                        $extensions        Extension activation, disabling and removal.
     * @param TrustStore $trust Extension signing keys, and the installation-wide
     *         lifecycle lock the trust and extension writes are taken under.
     * @param AutomationManagementService $automation Schedules and jobs behind the automation tools.
     * @param BusinessDefinitionService $definitions Business entity definition drafts and versions.
     * @param  BusinessSchemaService                   $schema            Schema plans and their approval and execution.
     * @param  BusinessMcpHandlers                     $businessRecords   Bounded generated-business MCP delegate.
     * @param  ReportMcpHandlers                       $businessReports   Bounded report and export MCP delegate.
     * @param  McpMutationGuard                        $mutations         Idempotency fence every write is run through.
     * @param ClockInterface $clock Supplies the first-run instant a new schedule is
     *         anchored to.
     * @param AuthorizationGateway $authorization Judges each write against the resource it names,
     *         before the fence is entered.
     * @param  ?ExecutionContext                       $executionContext  Actor bound by `forContext()`; null while the
     *         instance is unbound.
     * @param  ?Closure                                $contextRefresh    Callback bound by `forCredential()` that
     *         re-verifies the retained token and mints a fresh context; null when no credential is retained.
     * @param  ?ExtensionExecutionGate                 $extensionRuntime  Live authority for the resident extension
     *         generation; null only in isolated tests that have no extension runtime.
     * @param ?StudioMachineAuthoringGateway $studioAuthoring Machine entry to the browser's Studio authoring
     *         host; null only in isolated tests that exercise no Studio tool.
     * @param ?MediaService $media Media library the media tools browse, read, upload
     *         and delete through; null only in isolated tests that exercise no media tool.
     * @param ?MessageOverrideService $wording Wording overrides the wording tools list, search,
     *         save and withdraw through; null only in isolated tests that exercise no wording tool.
     * @param  ?BusinessSecurityAdministrationService  $businessSecurity  Business Security read model the overview
     *         tool answers from; null only in isolated tests that exercise no Business Security tool.
     * @param ?ContentModelService $models Content types and workflows the model tools list,
     *         read, create and update; null only in isolated tests that exercise no model tool.
     * @param ?StudioContentCompositionService $compositions Blueprint compositions the composition tools read
     *         and provision; null only in isolated tests that exercise no composition tool.
     * @param ?StudioMachineCompositionGateway $blueprints Machine entry to the composition screen's Studio host
     *         the Blueprint tools edit through; null only in isolated tests that exercise no Blueprint tool.
     *
     * @since  2.0.0
     */
    public function __construct(
        private McpCapabilityCatalog $catalog,
        private ContentService $content,
        private NavigationService $navigation,
        private AccessControlService $access,
        private SiteSettings $settings,
        private ExtensionManager $extensions,
        private TrustStore $trust,
        private AutomationManagementService $automation,
        private BusinessDefinitionService $definitions,
        private BusinessSchemaService $schema,
        private BusinessMcpHandlers $businessRecords,
        private ReportMcpHandlers $businessReports,
        private McpMutationGuard $mutations,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private ?ExecutionContext $executionContext = null,
        private ?Closure $contextRefresh = null,
        private ?ExtensionExecutionGate $extensionRuntime = null,
        private ?StudioMachineAuthoringGateway $studioAuthoring = null,
        private ?MediaService $media = null,
        private ?MessageOverrideService $wording = null,
        private ?BusinessSecurityAdministrationService $businessSecurity = null,
        private ?ContentModelService $models = null,
        private ?StudioContentCompositionService $compositions = null,
        private ?StudioMachineCompositionGateway $blueprints = null,
    ) {
    }

    /**
     * Bind these handlers to one request's already-authenticated actor.
     *
     * The HTTP transport calls this per request, so every tool the session reaches runs as that request's
     * principal and is audited under it. Any retained stdio credential is dropped from the copy: a context
     * handed in here is the whole identity.
     *
     * @param   ExecutionContext  $context  Actor, site and provenance the copy's tools run under.
     *
     * @return  self  A copy carrying the same collaborators, bound to this context.
     *
     * @since   2.0.0
     */
    public function forContext(ExecutionContext $context): self
    {
        return new self(
            $this->catalog,
            $this->content,
            $this->navigation,
            $this->access,
            $this->settings,
            $this->extensions,
            $this->trust,
            $this->automation,
            $this->definitions,
            $this->schema,
            $this->businessRecords,
            $this->businessReports,
            $this->mutations,
            $this->clock,
            $this->authorization,
            $context,
            extensionRuntime: $this->extensionRuntime,
            studioAuthoring: $this->studioAuthoring,
            media: $this->media,
            wording: $this->wording,
            businessSecurity: $this->businessSecurity,
            models: $this->models,
            compositions: $this->compositions,
            blueprints: $this->blueprints,
        );
    }

    /**
     * Bind a retained stdio credential that is reverified before every protected handler access.
     *
     * The stdio server outlives any single request, so the token is not resolved once at start-up: the closure
     * stored here re-runs `AccessTokenVerifier::verify()` on every context lookup, which is what makes a
     * revoked, expired or re-scoped token stop the very next tool call rather than the next process. Each
     * refresh mints a fresh random request identifier, so calls made in one long session stay apart in the
     * audit trail.
     *
     * @param   AccessTokenVerifier  $tokens          Verifier the retained token is presented to again.
     * @param   string               $token           Bearer credential the stdio session was opened with.
     * @param   string               $siteIdentifier  Site the token is presented against; normalised here, so
     *          verification and every tool agree on one spelling.
     *
     * @return  self  A copy that resolves its actor from the credential on every protected access.
     *
     * @throws  InvalidArgumentException  When the site identifier is not a usable site name.
     *
     * @since   2.0.0
     */
    public function forCredential(
        AccessTokenVerifier $tokens,
        string $token,
        string $siteIdentifier = SiteContext::DEFAULT,
    ): self {
        $site = SiteContext::fromString($siteIdentifier);
        $siteIdentifier = $site->identifier();
        $refresh = static function () use ($tokens, $token, $site, $siteIdentifier): ExecutionContext {
            $verified = $tokens instanceof ScopedAccessTokenVerifier
                ? $tokens->verifyScoped($token, 'kumwe-mcp', 'mcp', $siteIdentifier)
                : null;
            $principal = $verified !== null
                ? $verified->principal
                : (!($tokens instanceof ScopedAccessTokenVerifier)
                    ? $tokens->verify($token, 'kumwe-mcp', 'mcp', $siteIdentifier)
                    : null);
            $principal ??= throw new InsufficientCapability('authenticated');

            return $verified !== null
                ? $verified->context(
                    'mcp-stdio-' . bin2hex(random_bytes(16)),
                    AuthenticatedSurface::Mcp,
                )
                : $principal->context(
                    $site,
                    AuthenticationStrength::BearerToken,
                    'mcp-stdio-' . bin2hex(random_bytes(16)),
                    surface: AuthenticatedSurface::Mcp,
                );
        };

        return new self(
            $this->catalog,
            $this->content,
            $this->navigation,
            $this->access,
            $this->settings,
            $this->extensions,
            $this->trust,
            $this->automation,
            $this->definitions,
            $this->schema,
            $this->businessRecords,
            $this->businessReports,
            $this->mutations,
            $this->clock,
            $this->authorization,
            contextRefresh: $refresh,
            extensionRuntime: $this->extensionRuntime,
            studioAuthoring: $this->studioAuthoring,
            media: $this->media,
            wording: $this->wording,
            businessSecurity: $this->businessSecurity,
            models: $this->models,
            compositions: $this->compositions,
            blueprints: $this->blueprints,
        );
    }

    /**
     * Open a Studio authoring session bound to this credential for one exact create or edit target.
     *
     * @param   string   $intent              `create` or `edit`.
     * @param   ?string  $content             Entry to edit; required for `edit`.
     * @param   ?string  $contentType         Reusable Content type a `create` starts from, or null for blank.
     * @param   ?int     $contentTypeVersion  Exact type version, or null for its current version.
     *
     * @return  array{document: string}  The session document as canonical JSON.
     *
     * @throws  StudioMachineAuthoringRefused  When the target, live authority or session policy refuses.
     *
     * @since   2.0.0
     */
    public function openStudioAuthoringSession(
        string $intent,
        ?string $content = null,
        ?string $contentType = null,
        ?int $contentTypeVersion = null,
    ): array {
        $this->require('content.read');
        $session = $this->studioAuthoring()->open(
            $this->context(),
            StudioAuthoringIntent::tryFrom($intent)
                ?? throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid'),
            $content,
            $contentType,
            $contentTypeVersion,
        );

        return ['document' => self::studioJson($session->toDocument())];
    }

    /**
     * Resolve the declared target of an opened Studio authoring session.
     *
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringResolveTarget(
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringRead(
            StudioMachineAuthoringOperation::ResolveTarget,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Page through the reusable Content types a Studio authoring session may start from.
     *
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringListTypes(
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringRead(
            StudioMachineAuthoringOperation::ListTypes,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Start the coordinated Studio authoring session from one exact start source.
     *
     * The `operationId` is the Studio host's replay key: a retry with the same document replays the stored
     * result, and the same key with a changed document is refused by that host.
     *
     * @param   string   $operationId        Caller-chosen stable replay identity.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringStart(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringPerform(
            StudioMachineAuthoringOperation::Start,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Plan one Studio save outcome against live state and disclose its consequences.
     *
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringPlanSave(
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringRead(
            StudioMachineAuthoringOperation::PlanSave,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Commit the Content item an accepted Studio save plan authorizes.
     *
     * The `operationId` is the Studio host's replay key: a retry with the same document replays the stored
     * result, and the same key with a changed document is refused by that host.
     *
     * @param   string   $operationId        Caller-chosen stable replay identity.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringSaveItem(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringPerform(
            StudioMachineAuthoringOperation::SaveItem,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Create a new reusable Content type from the Studio session's design.
     *
     * The `operationId` is the Studio host's replay key: a retry with the same document replays the stored
     * result, and the same key with a changed document is refused by that host.
     *
     * @param   string   $operationId        Caller-chosen stable replay identity.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringSaveAsNewType(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringPerform(
            StudioMachineAuthoringOperation::SaveAsNewType,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Publish an immutable successor version of the session's reusable type.
     *
     * The `operationId` is the Studio host's replay key: a retry with the same document replays the stored
     * result, and the same key with a changed document is refused by that host.
     *
     * @param   string   $operationId        Caller-chosen stable replay identity.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The operation's argument as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioAuthoringSaveNewTypeVersion(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        $this->require('content.read');

        return $this->studioAuthoringPerform(
            StudioMachineAuthoringOperation::SaveNewTypeVersion,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $locale,
        );
    }

    /**
     * Dispatch one read authoring operation for the bound actor.
     *
     * @param   StudioMachineAuthoringOperation  $operation          Operation to dispatch.
     * @param   string                           $session            Opaque session key.
     * @param   string                           $sessionGeneration  Echoed session generation.
     * @param   string                           $document           Canonical JSON argument.
     * @param   ?string                          $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    private function studioAuthoringRead(
        StudioMachineAuthoringOperation $operation,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale,
    ): array {
        $result = $this->studioAuthoring()->perform(
            $this->context(),
            $operation,
            $session,
            $sessionGeneration,
            self::studioArgument($document),
            null,
            $locale,
        );

        return [
            'operation' => $operation->value,
            'replayed' => $result->replayed,
            'document' => self::studioJson($result->value()),
        ];
    }

    /**
     * Dispatch one mutating authoring operation keyed by the caller's operation identity.
     *
     * @param   StudioMachineAuthoringOperation  $operation          Operation to dispatch.
     * @param   string                           $operationId        Studio host replay key.
     * @param   string                           $session            Opaque session key.
     * @param   string                           $sessionGeneration  Echoed session generation.
     * @param   string                           $document           Canonical JSON argument.
     * @param   ?string                          $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    private function studioAuthoringPerform(
        StudioMachineAuthoringOperation $operation,
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale,
    ): array {
        $result = $this->studioAuthoring()->perform(
            $this->context($operationId),
            $operation,
            $session,
            $sessionGeneration,
            self::studioArgument($document),
            $operationId,
            $locale,
        );

        return [
            'operation' => $operation->value,
            'replayed' => $result->replayed,
            'document' => self::studioJson($result->value()),
        ];
    }

    /**
     * Open a Blueprint composition session bound to this credential for one provisioned Content type version.
     *
     * @param   string   $contentType         Content type UUID.
     * @param   int      $contentTypeVersion  Exact Content type version.
     * @param   ?string  $mode                `blueprint` (the default) or `read-only`.
     *
     * @return  array{document: string}  The session document as canonical JSON.
     *
     * @throws  StudioMachineAuthoringRefused  When the composition, theme lock or session policy refuses.
     *
     * @since   2.0.0
     */
    public function openStudioBlueprintSession(
        string $contentType,
        int $contentTypeVersion,
        ?string $mode = null,
    ): array {
        $this->require('content.read');
        if (!in_array($mode ?? 'blueprint', ['blueprint', 'read-only'], true)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
        }
        $session = $this->studioBlueprints()->open(
            $this->context(),
            $contentType,
            $contentTypeVersion,
            $mode === 'read-only',
        );

        return ['document' => self::studioJson($session->toDocument())];
    }

    /**
     * Load the session's Blueprint, or one immutable historical revision of it.
     *
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The artifact reference as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioBlueprintLoad(
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        return $this->studioBlueprint(
            StudioMachineCompositionOperation::Load,
            null,
            $session,
            $sessionGeneration,
            $document,
            null,
            $locale,
        );
    }

    /**
     * List the exact dependencies the session's Blueprint revision locks.
     *
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The artifact reference as one canonical JSON object.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Canonical result document.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioBlueprintDependencies(
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $locale = null,
    ): array {
        return $this->studioBlueprint(
            StudioMachineCompositionOperation::Dependencies,
            null,
            $session,
            $sessionGeneration,
            $document,
            null,
            $locale,
        );
    }

    /**
     * Save one schema-valid draft revision of the session's Blueprint.
     *
     * @param   string   $operationId        Studio host replay key.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The complete Blueprint document as one canonical JSON object.
     * @param   string   $expectedRevision   Revision the save replaces.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioBlueprintSave(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        string $expectedRevision,
        ?string $locale = null,
    ): array {
        return $this->studioBlueprint(
            StudioMachineCompositionOperation::Save,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $expectedRevision,
            $locale,
        );
    }

    /**
     * Publish the session's Blueprint draft.
     *
     * @param   string   $operationId        Studio host replay key.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The artifact reference as one canonical JSON object.
     * @param   string   $expectedRevision   Revision the publication replaces.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioBlueprintPublish(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        string $expectedRevision,
        ?string $locale = null,
    ): array {
        return $this->studioBlueprint(
            StudioMachineCompositionOperation::Publish,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $expectedRevision,
            $locale,
        );
    }

    /**
     * Return the session's published Blueprint to draft.
     *
     * @param   string   $operationId        Studio host replay key.
     * @param   string   $session            Opaque session key the open tool returned.
     * @param   string   $sessionGeneration  Session generation the open tool returned.
     * @param   string   $document           The artifact reference as one canonical JSON object.
     * @param   string   $expectedRevision   Revision the withdrawal replaces.
     * @param   ?string  $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Committed or replayed result.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    public function studioBlueprintUnpublish(
        string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        string $expectedRevision,
        ?string $locale = null,
    ): array {
        return $this->studioBlueprint(
            StudioMachineCompositionOperation::Unpublish,
            $operationId,
            $session,
            $sessionGeneration,
            $document,
            $expectedRevision,
            $locale,
        );
    }

    /**
     * Dispatch one Blueprint artifact operation, keyed by the caller's operation identity when it mutates.
     *
     * @param   StudioMachineCompositionOperation  $operation          Operation to dispatch.
     * @param   ?string                            $operationId        Studio host replay key of a mutation.
     * @param   string                             $session            Opaque session key.
     * @param   string                             $sessionGeneration  Echoed session generation.
     * @param   string                             $document           Canonical JSON argument.
     * @param   ?string                            $expectedRevision   Revision a mutation replaces.
     * @param   ?string                            $locale             Caller locale tag, or null.
     *
     * @return  array{operation: string, replayed: bool, document: string}  Result with `{value, revision}`.
     *
     * @throws  StudioMachineAuthoringRefused  When the document is malformed or the Studio host refuses.
     *
     * @since   2.0.0
     */
    private function studioBlueprint(
        StudioMachineCompositionOperation $operation,
        ?string $operationId,
        string $session,
        string $sessionGeneration,
        string $document,
        ?string $expectedRevision,
        ?string $locale,
    ): array {
        $this->require('content.read');
        $result = $this->studioBlueprints()->perform(
            $this->context($operationId),
            $operation,
            $session,
            $sessionGeneration,
            self::studioArgument($document),
            $expectedRevision,
            $operationId,
            $locale,
        );
        $answer = $result->toDocument();

        return [
            'operation' => $operation->value,
            'replayed' => $result->replayed,
            'document' => self::studioJson((object) ['value' => $answer->value, 'revision' => $answer->revision]),
        ];
    }

    /**
     * Return the Blueprint composition gateway, refusing when this instance was composed without one.
     *
     * @return  StudioMachineCompositionGateway  Machine entry to the composition screen's Studio host.
     *
     * @throws  \LogicException  When the handlers were composed without Blueprint composition.
     *
     * @since   2.0.0
     */
    private function studioBlueprints(): StudioMachineCompositionGateway
    {
        return $this->blueprints
            ?? throw new \LogicException('The MCP handlers were composed without Blueprint composition.');
    }

    /**
     * Return the Studio authoring gateway, refusing when this instance was composed without one.
     *
     * @return  StudioMachineAuthoringGateway  Machine entry to the browser's Studio host.
     *
     * @throws  \LogicException  When the handlers were composed without Studio authoring.
     *
     * @since   2.0.0
     */
    private function studioAuthoring(): StudioMachineAuthoringGateway
    {
        return $this->studioAuthoring
            ?? throw new \LogicException('The MCP handlers were composed without Studio authoring.');
    }

    /**
     * Decode one canonical JSON argument, preserving empty objects as objects.
     *
     * The protocol layer decodes tool arguments associatively, which erases the `{}` / `[]` distinction the
     * pinned Studio schemas depend on, so the argument travels as one JSON string and is decoded here.
     *
     * @param   string  $document  JSON object text.
     *
     * @return  \stdClass  Decoded argument.
     *
     * @throws  StudioMachineAuthoringRefused  When the text is not one JSON object.
     *
     * @since   2.0.0
     */
    private static function studioArgument(string $document): \stdClass
    {
        try {
            $decoded = json_decode($document, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }
        if (!$decoded instanceof \stdClass) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }

        return $decoded;
    }

    /**
     * Encode one Studio document exactly, keeping empty objects and slashes as written.
     *
     * @param   \stdClass  $document  Studio document.
     *
     * @return  string  Compact JSON text.
     *
     * @throws  JsonException  When the document cannot be encoded.
     *
     * @since   2.0.0
     */
    private static function studioJson(\stdClass $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Publish everything this release exposes over MCP and the policy metadata for each tool.
     *
     * The only tool that checks no capability of its own, so a client can learn the shape of the surface it
     * may then be refused parts of. The result carries no schemas, handler internals or caller data; the
     * risk class, required capability and non-MCP alternative are public contract metadata.
     *
     * @return  array{
     *              product: string, mode: string, tools: list<string>, resources: list<string>,
     *              prompts: list<string>, tool_metadata: list<array{
     *                  name: string, capability: string|null, risk: string, alternative: string
     *              }>
     *          }  Public surface identity and per-tool policy metadata.
     *
     * @since   2.0.0
     */
    public function discover(): array
    {
        $this->principal();

        return $this->catalog->publicSummary();
    }

    /**
     * List the content entries of the caller's site that the caller may read.
     *
     * The service's default page size applies, so at most one hundred readable entries come back; this is a
     * survey tool, not an export. A short result means the store ran out, never that permission trimmed it.
     *
     * @param   bool  $includeDeleted  Whether trashed entries join the result.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised records under `items`, most recently
     *          updated first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listContent(bool $includeDeleted = false): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            static fn (ContentRecord $record): array => $record->toArray(),
            $this->content->list($this->context(), includeDeleted: $includeDeleted),
        )];
    }

    /**
     * Create a draft page and return the record as stored.
     *
     * `$body` is a convenience for the ordinary single-field page: it is used only while `$data` is empty, and
     * any non-empty `$data` is stored instead of it rather than merged with it.
     *
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   string                $title        Human-readable title of the new entry.
     * @param   string                $slug         URL segment the entry becomes reachable at in its site.
     * @param   string                $body         Page body, used only while `$data` is empty.
     * @param   ?string               $contentType  Content type to create under, or null for the core page type.
     * @param   array<string, mixed>  $data         Field values for that type; when empty, `$body` is stored
     *          under a `body` key instead.
     *
     * @return  array<string, mixed>  The stored record, carrying the identifier and version a later update
     *          has to quote back.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.create`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `content.create` on the
     *          content collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createContent(
        string $operationId,
        string $title,
        string $slug,
        string $body = '',
        ?string $contentType = null,
        array $data = [],
    ): array {
        $this->require('content.create');
        $this->preauthorize($operationId, 'content.create', AuthorizationResource::collection('content'));

        return $this->mutations->run($this->context($operationId), 'content.create', $operationId, [
            'title' => $title, 'slug' => $slug, 'body' => $body, 'content_type' => $contentType, 'data' => $data,
        ], fn (): array => $this->content->create(
            $this->context($operationId),
            $title,
            $slug,
            $data === [] ? ['body' => $body] : $data,
            contentTypeIdentifier: $contentType ?? ContentService::CORE_PAGE_TYPE_ID,
        )->toArray());
    }

    /**
     * Replace a page's title, slug and fields at an expected version.
     *
     * The version is the concurrency check: a client that read an entry, thought about it, and wrote it back
     * is refused if someone else wrote in between, instead of silently discarding that edit. As with creation,
     * `$body` applies only while `$data` is empty.
     *
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   string                $id           UUID of the entry to rewrite.
     * @param   int                   $version      Version the caller last read; the stored entry must still
     *          be at it.
     * @param   string                $title        Replacement title.
     * @param   string                $slug         Replacement URL segment.
     * @param   string                $body         Page body, used only while `$data` is empty.
     * @param   array<string, mixed>  $data         Replacement field values; when empty, `$body` is stored
     *          under a `body` key instead.
     *
     * @return  array<string, mixed>  The stored record with its version incremented.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.update`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `content.update` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateContent(
        string $operationId,
        string $id,
        int $version,
        string $title,
        string $slug,
        string $body = '',
        array $data = [],
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::item('content', $id));

        return $this->mutations->run($this->context($operationId), 'content.update', $operationId, [
            'id' => $id, 'version' => $version, 'title' => $title, 'slug' => $slug, 'body' => $body, 'data' => $data,
        ], fn (): array => $this->content->update(
            $this->context($operationId),
            $id,
            $version,
            $title,
            $slug,
            $data === [] ? ['body' => $body] : $data,
        )->toArray());
    }

    /**
     * Move a content entry to another workflow state, under the capability that particular move demands.
     *
     * Unlike every other write here, the capability is not fixed: it is resolved from the entry's own workflow
     * first, so publishing asks for a publish capability while an installation-defined state asks for whatever
     * its edge declares. Resolving it reads the entry under `content.read` first, so an entry this caller
     * cannot see, or a move the workflow does not declare, is refused before the transition is ever
     * authorized.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the entry to move.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     * @param   string  $status       State key to move to, spelled as the workflow in force spells it.
     *
     * @return  array<string, mixed>  The stored record in its new state, with its version incremented.
     *
     * @throws  InsufficientCapability  When no principal is bound to these handlers.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses the resolved transition
     *          capability on this entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function transitionContent(string $operationId, string $id, int $version, string $status): array
    {
        $target = $status;
        $this->preauthorize(
            $operationId,
            $this->content->transitionCapability($this->context($operationId), $id, $target)->value(),
            AuthorizationResource::item('content', $id),
        );

        return $this->mutations->run($this->context($operationId), 'content.transition', $operationId, [
            'id' => $id, 'version' => $version, 'status' => $status,
        ], fn (): array => $this->content->transition(
            $this->context($operationId),
            $id,
            $version,
            $target,
        )->toArray());
    }

    /**
     * Move a content entry to the trash at an expected version.
     *
     * Reversible: the row and its version line survive, `restoreContent()` brings the entry back, and until
     * then it is absent from listings that do not ask for deleted entries.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the entry to trash.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     *
     * @return  array<string, mixed>  The stored record in its trashed state.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.delete`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `content.delete` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function trashContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.delete');
        $this->preauthorize($operationId, 'content.delete', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.trash',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->trash($this->context($operationId), $id, $version)->toArray()
        );
    }

    /**
     * Bring a trashed content entry back into the live listing at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the trashed entry to restore.
     * @param   int     $version      Version the caller last read; the stored entry must still be at it.
     *
     * @return  array<string, mixed>  The stored record in the state it is restored to.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.restore`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `content.restore` on this
     *          entry.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function restoreContent(string $operationId, string $id, int $version): array
    {
        $this->require('content.restore');
        $this->preauthorize($operationId, 'content.restore', AuthorizationResource::item('content', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'content.restore',
            $operationId,
            compact('id', 'version'),
            fn (): array => $this->content->restore($this->context($operationId), $id, $version)->toArray()
        );
    }

    /**
     * List the navigation menus of the caller's site.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised menus under `items`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function listMenus(): array
    {
        $this->require('navigation.manage');

        return ['items' => array_map(
            static fn (MenuRecord $menu): array => $menu->toArray(),
            $this->navigation->menus($this->context()),
        )];
    }

    /**
     * Create an empty navigation menu.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $handle       Stable machine handle that templates and settings refer to the menu by.
     * @param   string  $title        Operator-facing label for the menu.
     *
     * @return  array<string, mixed>  The stored menu, carrying the identifier its items are created against.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `navigation.manage` on the
     *          menu collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createMenu(string $operationId, string $handle, string $title): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::collection('menu'));

        return $this->mutations->run($this->context($operationId), 'menu.create', $operationId, [
            'handle' => $handle, 'title' => $title,
        ], fn (): array => $this->navigation->createMenu(
            $this->context($operationId),
            $handle,
            $title,
        )->toArray());
    }

    /**
     * List the items of one menu.
     *
     * Every item carries its materialised path and its parent, so a client can rebuild the tree from this one
     * call rather than walking parents.
     *
     * @param   string  $menuId  UUID of the menu whose items are wanted.
     *
     * @return  array{items: list<array<string, mixed>>}  Serialised items under `items`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function listMenuItems(string $menuId): array
    {
        $this->require('navigation.manage');
        return ['items' => array_map(
            static fn (MenuItemRecord $item): array => $item->toArray(),
            $this->navigation->items($this->context(), $menuId)
        )];
    }

    /**
     * Read one menu item, including the target it resolves to.
     *
     * Worth calling before an update: `updateMenuItem()` merges against the stored item, so this is how a
     * client learns what it is about to keep.
     *
     * @param   string  $id  UUID of the item to read.
     *
     * @return  array<string, mixed>  The stored item, carrying its path, parent, version and typed target.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function getMenuItem(string $id): array
    {
        $this->require('navigation.manage');

        return $this->navigation->item($this->context(), $id)->toArray();
    }

    /**
     * Create a menu item under a menu, optionally beneath an existing item.
     *
     * The optional fields are declared as plain strings in the tool schema, so an empty string is how a
     * client says "none" here: an empty parent puts the item at the menu root, and an empty target field
     * leaves that part of the target unset.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $menuId       UUID of the menu the item belongs to; items never move between menus.
     * @param   string  $title        Label the navigation renders for this item.
     * @param   string  $slug         URL segment this item contributes to its path.
     * @param   int     $position     Sort order among siblings; lower values render first.
     * @param   string  $parentId     UUID of the parent item, or empty for a root-level item.
     * @param   string  $targetType   What the item points at — `content`, `anchor` or `url` — or empty.
     * @param   string  $contentId    Content the item resolves to for a content or anchor target, or empty.
     * @param   string  $targetUrl    Anchor fragment or external link, or empty.
     *
     * @return  array<string, mixed>  The stored item, with the path resolved from its parent and slug.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this menu.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createMenuItem(
        string $operationId,
        string $menuId,
        string $title,
        string $slug,
        int $position = 0,
        string $parentId = '',
        string $targetType = '',
        string $contentId = '',
        string $targetUrl = '',
    ): array {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu', $menuId));
        $input = compact(
            'menuId',
            'title',
            'slug',
            'position',
            'parentId',
            'targetType',
            'contentId',
            'targetUrl',
        );
        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.create',
            $operationId,
            $input,
            fn (): array => $this->navigation->createItem(
                $this->context($operationId),
                $menuId,
                $parentId === '' ? null : $parentId,
                $title,
                $slug,
                $position,
                $targetType === '' ? null : $targetType,
                $contentId === '' ? null : $contentId,
                $targetUrl === '' ? null : $targetUrl,
            )->toArray()
        );
    }

    /**
     * Update a menu item's label, placement and target at an expected version.
     *
     * The optional arguments are merged against the stored item rather than applied blindly, which is what
     * lets a client rename an item without restating its whole target: null falls back to the stored value
     * and an empty string clears it. The target triple moves as a unit — supplying any one of `$targetType`,
     * `$contentId` or `$targetUrl` rewrites all three from that merge, and supplying none leaves the stored
     * target alone. A move also rewrites every descendant's path and bumps their versions, so a client
     * holding a child copy has to re-read it.
     *
     * @param   string   $operationId  Idempotency key this write is fenced on.
     * @param   string   $id           UUID of the item to update.
     * @param   int      $version      Version the caller last read; the stored item must still be at it.
     * @param   string   $title        Replacement label.
     * @param   string   $slug         Replacement URL segment.
     * @param   ?int     $position     Replacement sort order, or null to keep the stored one.
     * @param   ?string  $parentId     New parent, empty to move the item to the root, or null to keep the
     *          stored parent.
     * @param   ?string  $targetType   `content`, `anchor` or `url`; null falls back to the stored type.
     * @param   ?string  $contentId    Replacement content target, empty to clear it, null to fall back to
     *          the stored one.
     * @param   ?string  $targetUrl    Replacement fragment or link, empty to clear it, null to fall back to
     *          the stored one.
     *
     * @return  array<string, mixed>  The stored item, with its version incremented and its path re-resolved.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this item.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateMenuItem(
        string $operationId,
        string $id,
        int $version,
        string $title,
        string $slug,
        ?int $position = null,
        ?string $parentId = null,
        ?string $targetType = null,
        ?string $contentId = null,
        ?string $targetUrl = null,
    ): array {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu_item', $id));
        $stored = $this->navigation->item($this->context($operationId), $id);
        $targetChanged = $targetType !== null || $contentId !== null || $targetUrl !== null;
        $input = compact(
            'id',
            'version',
            'title',
            'slug',
            'position',
            'parentId',
            'targetType',
            'contentId',
            'targetUrl',
        );

        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.update',
            $operationId,
            $input,
            fn (): array => $this->navigation->updateItem(
                $this->context($operationId),
                $id,
                $version,
                $parentId === null ? $stored->parentId : ($parentId === '' ? null : $parentId),
                $title,
                $slug,
                $position ?? $stored->position,
                $targetChanged ? ($targetType ?? $stored->targetType) : null,
                $targetChanged
                    ? ($contentId === null ? $stored->contentId : ($contentId === '' ? null : $contentId))
                    : null,
                $targetChanged
                    ? ($targetUrl === null ? $stored->targetUrl : ($targetUrl === '' ? null : $targetUrl))
                    : null,
            )->toArray(),
        );
    }

    /**
     * Delete one menu item at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the item to delete.
     * @param   int     $version      Version the caller last read; the stored item must still be at it.
     *
     * @return  array{deleted: bool}  Always `deleted: true`; a refusal arrives as an exception, never as false.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `navigation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `navigation.manage` on
     *          this item.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function deleteMenuItem(string $operationId, string $id, int $version): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu_item', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'menu-item.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->navigation->deleteItem($this->context($operationId), $id, $version);

                return ['deleted' => true];
            },
        );
    }

    /**
     * Read the site settings document as an administrator.
     *
     * @return  array<string, mixed>  Every public setting key, with defaults filled in for keys never stored.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `settings.manage`.
     *
     * @since   2.0.0
     */
    public function getSettings(): array
    {
        $this->require('settings.manage');

        return $this->settings->managed($this->context());
    }

    /**
     * Replace the site settings document with the supplied values.
     *
     * This is a whole-document write rather than a patch: the tool schema demands every managed key, and the
     * result is validated as a unit because the keys constrain one another — the nominated homepage and
     * primary menu have to exist in this site. A rejected value therefore leaves the previous document intact.
     *
     * @param   string                $operationId            Idempotency key this write is fenced on.
     * @param   string                $siteName               Display name shown in page chrome and titles.
     * @param   string                $homepageContentId      UUID of the entry served as the homepage.
     * @param   string                $defaultLocale          Locale the site falls back to.
     * @param   string                $timezone               Timezone site-facing dates are rendered in.
     * @param   bool                  $searchIndexingEnabled  Whether the site may be indexed by search engines.
     * @param   array<string, mixed>  $presentation           Theme document: logo, footer, menus, button and
     *          header styling, and the colour schemes the site may render with.
     *
     * @return  array<string, mixed>  The settings document as it stands after the write.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `settings.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `settings.manage` on this
     *          site.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateSettings(
        string $operationId,
        string $siteName,
        string $homepageContentId,
        string $defaultLocale,
        string $timezone,
        bool $searchIndexingEnabled,
        array $presentation,
    ): array {
        $this->require('settings.manage');
        $this->preauthorize(
            $operationId,
            'settings.manage',
            AuthorizationResource::item('site', $this->context()->site()->identifier()),
        );
        $values = [
            'site_name' => $siteName,
            'homepage_content_id' => $homepageContentId,
            'default_locale' => $defaultLocale,
            'timezone' => $timezone,
            'search_indexing_enabled' => $searchIndexingEnabled,
            'presentation' => $presentation,
        ];

        return $this->mutations->run(
            $this->context($operationId),
            'settings.update',
            $operationId,
            $values,
            function () use ($operationId, $values): array {
                $this->settings->updateAll($this->context($operationId), $values);

                return $this->settings->managed($this->context($operationId));
            },
        );
    }

    /**
     * List the users this credential may manage.
     *
     * Rows are filtered one by one rather than the whole call being refused, so an administrator scoped to
     * part of the installation sees a shorter list instead of an error.
     *
     * @return  array{items: list<array<string, mixed>>}  Visible users under `items`, each with its roles.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listUsers(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->users($this->context('users-list'))];
    }

    /**
     * List the manageable roles together with the capability vocabulary a grant may name.
     *
     * The two travel in one response because a client cannot compose a role without knowing which capability
     * codes exist, and this surface offers no second call for them.
     *
     * @return  array{
     *            items: list<array<string, mixed>>,
     *            capabilities: list<array{code: string, description: string}>
     *          }
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listRoles(): array
    {
        $this->require('users.manage');
        $context = $this->context('roles-list');
        return ['items' => $this->access->roles($context), 'capabilities' => $this->access->capabilities($context)];
    }

    /**
     * Update a user's address, display name and account status at an expected version.
     *
     * Two guards stand in front of the write: an actor may not move its own account to a status that cannot
     * sign in, and the requested status has to be a legal move from the one currently stored. The user's
     * security epoch advances as the edit lands, so every token issued before it stops verifying — editing an
     * account is also a credential revocation.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the user to update.
     * @param   int     $version      Version the caller last read; the stored user must still be at it.
     * @param   string  $email        Replacement address for the account.
     * @param   string  $displayName  Replacement human-readable name.
     * @param   string  $status       Account state to store: `pending`, `active`, `suspended` or `disabled`.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `users.manage` on this
     *          user.
     * @throws  \ValueError  When the status is not one of the stored account states.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function updateUser(
        string $operationId,
        string $id,
        int $version,
        string $email,
        string $displayName,
        string $status,
    ): array {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('user', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'user.update',
            $operationId,
            compact('id', 'version', 'email', 'displayName', 'status'),
            function () use ($operationId, $id, $version, $email, $displayName, $status): array {
                $this->access->updateUser(
                    $this->context($operationId),
                    $id,
                    $email,
                    $displayName,
                    UserStatus::from($status),
                    $version,
                );
                return ['updated' => true];
            }
        );
    }

    /**
     * Create a permission role, initially conferring nothing.
     *
     * Capabilities are attached separately, so a role created here is inert until it is granted something.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $code         Stable machine code assignments refer to the role by.
     * @param   string  $name         Operator-facing label for the role.
     *
     * @return  array{id: string}  UUID of the stored role, under `id`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `users.manage` on the role
     *          collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createRole(string $operationId, string $code, string $name): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::collection('role'));
        return $this->mutations->run(
            $this->context($operationId),
            'role.create',
            $operationId,
            compact('code', 'name'),
            fn (): array => ['id' => $this->access->createRole($this->context($operationId), $code, $name)]
        );
    }

    /**
     * List the API token metadata issued for the caller's site.
     *
     * Metadata only. A token's plaintext exists solely in the response that minted it, so nothing here can be
     * replayed as a credential.
     *
     * @return  array{items: list<array<string, mixed>>}  Token rows under `items`, newest first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     *
     * @since   2.0.0
     */
    public function listTokens(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->tokens($this->context('tokens-list'))];
    }

    /**
     * Revoke one API or MCP token immediately.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $tokenId      UUID of the token to kill.
     *
     * @return  array{revoked: bool}  Always `revoked: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `users.manage` on this
     *          token.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeToken(string $operationId, string $tokenId): array
    {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('api_token', $tokenId));

        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke',
            $operationId,
            ['token_id' => $tokenId],
            function () use ($operationId, $tokenId): array {
                $this->access->revokeToken($this->context($operationId), $tokenId);

                return ['revoked' => true];
            },
        );
    }

    /**
     * Invalidate every token one user holds, in every site, by advancing their security epoch.
     *
     * The break-glass action for a compromised account: it reaches credentials this site never issued and
     * cannot be undone, so the user has to be issued fresh ones afterwards. Reach for
     * `revokeSubjectSiteTokens()` when only this site is affected.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $userId       UUID of the user whose credentials are being burned.
     * @param   string  $reason       Operator-facing justification recorded with the revocation.
     *
     * @return  array{revoked: int}  How many live tokens were revoked, under `revoked`; zero when the
     *          subject held none.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `users.manage` on this
     *          user.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function emergencyRevokeSubjectTokens(
        string $operationId,
        string $userId,
        string $reason,
    ): array {
        $this->require('users.manage');
        $this->preauthorize($operationId, 'users.manage', AuthorizationResource::item('user', $userId));
        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke-subject',
            $operationId,
            compact('userId', 'reason'),
            fn (): array => ['revoked' => $this->access->emergencyRevokeAllSubjectTokens(
                $this->context($operationId),
                $userId,
                $reason,
            )],
        );
    }

    /**
     * Revoke every token one user holds in the caller's site, leaving their other sites alone.
     *
     * The site-scoped counterpart to `emergencyRevokeSubjectTokens()`, and the right tool for an off-boarding
     * from one site. Authorization is asked against the site rather than the user, because the site is the
     * boundary actually being cleared.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $userId       UUID of the user whose tokens for this site are withdrawn.
     * @param   string  $reason       Operator-facing justification recorded with the revocation.
     *
     * @return  array{revoked: int}  How many of this site's tokens were revoked, under `revoked`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `users.manage` on this
     *          site.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeSubjectSiteTokens(string $operationId, string $userId, string $reason): array
    {
        $this->require('users.manage');
        $this->preauthorize(
            $operationId,
            'users.manage',
            AuthorizationResource::item('site', $this->context($operationId)->site()->identifier()),
        );
        return $this->mutations->run(
            $this->context($operationId),
            'token.revoke-subject-site',
            $operationId,
            compact('userId', 'reason'),
            fn (): array => ['revoked' => $this->access->revokeSubjectTokens(
                $this->context($operationId),
                $userId,
                $reason,
            )],
        );
    }

    /**
     * List the newest identity and credential security events, newest first.
     *
     * The same closed identity-only projection the administrator access screen's events tab renders: at most
     * one hundred rows, and never an event's metadata.
     *
     * @return  array{items: list<array<string, mixed>>}  Security events under `items`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `users.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses installation identity management.
     *
     * @since   2.0.0
     */
    public function listSecurityEvents(): array
    {
        $this->require('users.manage');

        return ['items' => $this->access->securityEvents($this->context('security-events-list'))];
    }

    /**
     * List the extension signing keys and what still depends on each.
     *
     * Every row carries the active releases signed by that key, which is the number an operator needs before
     * finalizing a rotation: a key with dependents cannot be retired yet.
     *
     * @return  array{items: list<array<string, mixed>>}  Key rows under `items`, each with its
     *          `affected_extensions` list.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     *
     * @since   2.0.0
     */
    public function listTrustKeys(): array
    {
        $this->require('extensions.manage');
        return ['items' => $this->trust->keys($this->context('trust-keys-list'))];
    }

    /**
     * Register a constrained, expiring Ed25519 key that may sign extension packages.
     *
     * Trust is never open-ended here: a key is admitted only for one vendor namespace, one extension name
     * pattern and one expiry. The write is taken under the installation-wide extension lifecycle lock, so it
     * cannot interleave with an install or activation that is verifying against the key set.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $keyId             Identifier package signatures name this key by.
     * @param   string  $publicKeyBase64   Base64-encoded Ed25519 public key.
     * @param   string  $vendorNamespace   Vendor whose packages this key is allowed to sign.
     * @param   string  $extensionPattern  Extension name pattern the key is confined to.
     * @param   string  $expiresAt         Expiry as a date string; a key is never admitted without one.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on the
     *          trust key collection.
     * @throws  InvalidArgumentException  When the identifier, key, namespace, pattern or expiry fails
     *          validation, or the operation identifier is malformed or reused with different arguments.
     * @throws  \DateMalformedStringException  When the expiry is not a readable date string.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function addTrustKey(
        string $operationId,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        string $expiresAt,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::collection('extension_trust_key'),
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            'trust-key.add',
            $operationId,
            compact('keyId', 'publicKeyBase64', 'vendorNamespace', 'extensionPattern', 'expiresAt'),
            function () use (
                $operationId,
                $keyId,
                $publicKeyBase64,
                $vendorNamespace,
                $extensionPattern,
                $expiresAt,
            ): array {
                $this->trust->add(
                    $this->context($operationId),
                    $keyId,
                    $publicKeyBase64,
                    $vendorNamespace,
                    $extensionPattern,
                    new \DateTimeImmutable($expiresAt),
                );
                return ['updated' => true];
            },
        );
    }

    /**
     * Add a replacement signing key while the key it supersedes stays valid.
     *
     * Rotation is deliberately two-step. This half only introduces the new key: the old one keeps verifying,
     * so releases already installed under it continue to load. Finalizing is a separate call to
     * `revokeTrustKey()` without `$emergency`, and it is refused until nothing installed still names the old
     * key. The replacement has to preserve the superseded key's vendor namespace and extension pattern, so a
     * rotation cannot quietly widen what the key is allowed to sign. Like every trust write, this runs under
     * the extension lifecycle lock.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $oldKeyId          Identifier of the key being superseded; it must still be active.
     * @param   string  $newKeyId          Identifier the replacement key is registered under.
     * @param   string  $publicKeyBase64   Base64-encoded Ed25519 public key of the replacement.
     * @param   string  $vendorNamespace   Vendor whose packages the replacement may sign.
     * @param   string  $extensionPattern  Extension name pattern the replacement is confined to.
     * @param   string  $expiresAt         Expiry of the replacement, as a date string.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on the
     *          superseded key.
     * @throws  InvalidArgumentException  When an argument fails validation, no active key carries the old
     *          identifier, the replacement changes the namespace constraints, or the operation identifier is
     *          malformed or reused with different arguments.
     * @throws  \DateMalformedStringException  When the expiry is not a readable date string.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function rotateTrustKey(
        string $operationId,
        string $oldKeyId,
        string $newKeyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        string $expiresAt,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension_trust_key', $oldKeyId),
        );
        $input = compact(
            'oldKeyId',
            'newKeyId',
            'publicKeyBase64',
            'vendorNamespace',
            'extensionPattern',
            'expiresAt',
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            'trust-key.rotate',
            $operationId,
            $input,
            function () use (
                $operationId,
                $oldKeyId,
                $newKeyId,
                $publicKeyBase64,
                $vendorNamespace,
                $extensionPattern,
                $expiresAt,
            ): array {
                $this->trust->rotate(
                    $this->context($operationId),
                    $oldKeyId,
                    $newKeyId,
                    $publicKeyBase64,
                    $vendorNamespace,
                    $extensionPattern,
                    new \DateTimeImmutable($expiresAt),
                );
                return ['updated' => true];
            },
        );
    }

    /**
     * Finalize a rotation, or quarantine everything a compromised key ever signed.
     *
     * One tool with two very different outcomes, chosen by `$emergency`. Left false, this is the ordinary end
     * of a rotation and is refused while any active release still depends on the key. Set true, the key is
     * treated as compromised: every release it signed is quarantined at once, which takes those extensions out
     * of service until they are re-signed. Both paths run under the extension lifecycle lock.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $keyId        Identifier of the key being retired or disowned.
     * @param   string  $reason       Operator-facing justification recorded with the change.
     * @param   bool    $emergency    True to quarantine the key's releases immediately, false to finalize a
     *          completed rotation.
     *
     * @return  array<string, mixed>  For an emergency, the quarantined extension identifiers under
     *          `quarantined`, empty when the key signed nothing still installed; for a finalization,
     *          `updated: true`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this key.
     * @throws  InvalidArgumentException  When the identifier or reason is rejected, no active key carries the
     *          identifier, releases still depend on it, or the operation identifier is malformed or reused
     *          with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function revokeTrustKey(
        string $operationId,
        string $keyId,
        string $reason,
        bool $emergency = false,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension_trust_key', $keyId),
        );
        return $this->runTrustMutation(
            $this->context($operationId),
            $emergency ? 'trust-key.emergency-revoke' : 'trust-key.finalize',
            $operationId,
            compact('keyId', 'reason', 'emergency'),
            function () use ($operationId, $keyId, $reason, $emergency): array {
                $context = $this->context($operationId);
                if ($emergency) {
                    return ['quarantined' => $this->trust->emergencyRevoke($context, $keyId, $reason)];
                }
                $this->trust->finalizeRotation($context, $keyId, $reason);
                return ['updated' => true];
            },
        );
    }

    /**
     * List the installed extensions this credential may manage.
     *
     * @return  array{items: list<array<string, mixed>>}  Registry rows under `items`, each carrying the
     *          extension's identifier, type and lifecycle status.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     *
     * @since   2.0.0
     */
    public function listExtensions(): array
    {
        $this->require('extensions.manage');

        return ['items' => $this->extensions->installed($this->context())];
    }

    /**
     * Activate an installed extension so the next compiled runtime map carries it.
     *
     * A template is activated onto one presentation surface at a time and so needs `$surface`; every other
     * extension type leaves it unset. Taking over the administrator surface is the case that demands step-up
     * authentication, because a broken administrator theme locks operators out — and this surface cannot
     * supply it. No credential crosses a tool boundary, so the extension manager is always called with no
     * step-up proof and refuses that one change with `StepUpAuthenticationRequired`; the browser or
     * protected REST path remains the route for it. Every other activation
     * proceeds under the caller's existing `extensions.manage` authorization, taken under the
     * installation-wide extension lifecycle lock.
     *
     * @param   string   $operationId  Idempotency key this write is fenced on.
     * @param   string   $identifier   `vendor/name` identifier of the installed extension.
     * @param   ?string  $surface      `site` or `administrator` for a template; null or empty otherwise.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the change would take over
     *          the administrator surface, which no machine caller may prove.
     * @throws  InvalidArgumentException  When the surface is neither `site` nor `administrator`, or the
     *          operation identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function activateExtension(
        string $operationId,
        string $identifier,
        ?string $surface = null,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );

        $context = $this->context($operationId);
        $themeSurface = ThemeSurface::optional($surface);

        return $this->runExtensionMutation(
            $context,
            'extension.activate',
            $operationId,
            [
                'identifier' => $identifier,
                'surface' => $themeSurface?->value,
            ],
            fn (): array => $this->extensions->activate(
                $identifier,
                $context,
                $themeSurface,
            ),
        );
    }

    /**
     * Disable an installed extension so it stops contributing to the compiled runtime map.
     *
     * The reversible half of removal: the files stay on disk and the registry keeps the release, so
     * `activateExtension()` can put it back. An extension currently serving the administrator theme demands
     * step-up authentication, since disabling it changes what the administration UI renders with, and this
     * surface carries no credential with which to prove it: that one case is refused here and belongs to the
     * browser or the protected REST path. The console can restore the built-in administrator theme for
     * break-glass recovery, but cannot step up to disable a live administrator theme. Every other disable
     * proceeds under the
     * caller's existing `extensions.manage` authorization, taken under the extension lifecycle lock.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $identifier   `vendor/name` identifier of the installed extension.
     *
     * @return  array<string, mixed>  The registry row for the extension after the status change.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the extension is the live
     *          administrator theme, which no machine caller may prove a step-up for.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function disableExtension(
        string $operationId,
        string $identifier,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        $context = $this->context($operationId);
        return $this->runExtensionMutation(
            $context,
            'extension.disable',
            $operationId,
            ['identifier' => $identifier],
            fn (): array => $this->extensions->disable($identifier, $context),
        );
    }

    /**
     * Remove an extension from the registry and retire the files it was serving from.
     *
     * The one lifecycle change the registry cannot undo: the extension row and the capabilities its package
     * contributed go with it. The runtime directory is retired rather than deleted outright, so processes
     * still running an older compiled generation keep reading what they have until they drain. Removing the
     * extension that serves the live administrator theme demands a step-up this surface cannot supply and is
     * refused here; do that one in the browser or protected REST path. The console can first restore the
     * built-in administrator theme for break-glass recovery, after which the inactive extension can be removed.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $identifier   `vendor/name` identifier of the extension to remove.
     *
     * @return  array{uninstalled: bool}  Always `uninstalled: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `extensions.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `extensions.manage` on
     *          this extension.
     * @throws  \Kumwe\App\Presentation\Application\StepUpAuthenticationRequired  When the extension is the live
     *          administrator theme, which no machine caller may prove a step-up for.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function uninstallExtension(
        string $operationId,
        string $identifier,
    ): array {
        $this->require('extensions.manage');
        $this->preauthorize(
            $operationId,
            'extensions.manage',
            AuthorizationResource::item('extension', $identifier),
        );
        $context = $this->context($operationId);
        return $this->runExtensionMutation(
            $context,
            'extension.uninstall',
            $operationId,
            ['identifier' => $identifier],
            function () use ($context, $identifier): array {
                $this->extensions->uninstall($identifier, $context);
                return ['uninstalled' => true];
            }
        );
    }

    /**
     * List the recurring automation schedules of the caller's site.
     *
     * @return  array{items: list<array<string, mixed>>}  Schedule rows under `items`; empty when none is
     *          manageable by this credential.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     *
     * @since   2.0.0
     */
    public function listSchedules(): array
    {
        $this->require('automation.manage');

        return ['items' => $this->automation->schedules($this->context())];
    }

    /**
     * List the queued automation jobs this credential is allowed to see, most recent first.
     *
     * @param   int  $limit  Visible jobs to return; between 1 and 500.
     *
     * @return  array{items: list<array<string, mixed>>}  Job rows under `items`, most recent first; empty
     *          when none is visible.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  InvalidArgumentException  When the limit falls outside 1 to 500.
     *
     * @since   2.0.0
     */
    public function listJobs(int $limit = 100): array
    {
        $this->require('automation.manage');
        return ['items' => $this->automation->jobs($this->context(), $limit)];
    }

    /**
     * Create a recurring automation schedule.
     *
     * The first run is anchored to this object's clock, so a schedule created now becomes due from now rather
     * than from some implicit epoch. Occurrences are enqueued with an empty payload: a handler that needs
     * arguments has to be configured somewhere other than this surface.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $name         Operator-facing label the schedule is listed under.
     * @param   string  $cron         Five-field cron expression deciding when the schedule is due.
     * @param   string  $jobType      Registered handler type each occurrence enqueues.
     * @param   string  $timezone     Timezone the cron expression is evaluated in.
     * @param   string  $queue        Queue name the enqueued jobs are placed on.
     *
     * @return  array{id: string}  UUID of the stored schedule, under `id`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on the
     *          schedule collection.
     * @throws  InvalidArgumentException  When no handler is registered for the job type, the cron expression
     *          or timezone is rejected, or the operation identifier is malformed or reused with different
     *          arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function createSchedule(
        string $operationId,
        string $name,
        string $cron,
        string $jobType,
        string $timezone = 'UTC',
        string $queue = 'default',
    ): array {
        $this->require('automation.manage');

        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::collection('schedule'));
        return $this->mutations->run($this->context($operationId), 'schedule.create', $operationId, [
            'name' => $name, 'cron' => $cron, 'jobType' => $jobType,
            'timezone' => $timezone, 'queue' => $queue,
        ], fn (): array => ['id' => $this->automation->createSchedule(
            $this->context($operationId),
            $name,
            $cron,
            $timezone,
            $jobType,
            [],
            $queue,
            $this->clock->now(),
        )]);
    }

    /**
     * Resume or suspend a schedule at an expected version.
     *
     * Suspension stops dispatch without losing the schedule, so an agent can quiet a misbehaving job and hand
     * the decision to an operator instead of deleting the definition.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the schedule to toggle.
     * @param   int     $version      Version the caller last read; the stored schedule must still be at it.
     * @param   bool    $enabled      True to resume dispatching, false to suspend it.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this schedule.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function setScheduleEnabled(
        string $operationId,
        string $id,
        int $version,
        bool $enabled,
    ): array {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.update',
            $operationId,
            compact('id', 'version', 'enabled'),
            function () use ($operationId, $id, $version, $enabled): array {
                $this->automation->setScheduleEnabled($this->context($operationId), $id, $version, $enabled);
                return ['updated' => true];
            }
        );
    }

    /**
     * Delete a recurring schedule at an expected version.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the schedule to remove.
     * @param   int     $version      Version the caller last read; the stored schedule must still be at it.
     *
     * @return  array{deleted: bool}  Always `deleted: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this schedule.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function deleteSchedule(string $operationId, string $id, int $version): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('schedule', $id));
        return $this->mutations->run(
            $this->context($operationId),
            'schedule.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->automation->deleteSchedule($this->context($operationId), $id, $version);
                return ['deleted' => true];
            }
        );
    }

    /**
     * Requeue a dead job for another attempt.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the dead job to requeue.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no dead job carries that identifier, or the operation identifier
     *          is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function retryJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, true);
    }

    /**
     * Withdraw a pending job so it is never dispatched.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the pending job to withdraw.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no pending job carries that identifier, or the operation
     *          identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    public function cancelJob(string $operationId, string $id): array
    {
        return $this->jobAction($operationId, $id, false);
    }

    /**
     * Run the shared retry-or-cancel path for one job.
     *
     * Retrying and cancelling differ only in the audited operation name and the service call they make, so
     * they share one authorized and fenced body rather than two that could drift apart.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           UUID of the job being acted on.
     * @param   bool    $retry        True to requeue a dead job, false to cancel a pending one.
     *
     * @return  array{updated: bool}  Always `updated: true`; a refusal arrives as an exception.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `automation.manage`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `automation.manage` on
     *          this job.
     * @throws  InvalidArgumentException  When no job in the required state carries that identifier, or the
     *          operation identifier is malformed or reused with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the
     *          lease is lost before the write completes.
     *
     * @since   2.0.0
     */
    private function jobAction(string $operationId, string $id, bool $retry): array
    {
        $this->require('automation.manage');
        $this->preauthorize($operationId, 'automation.manage', AuthorizationResource::item('job', $id));
        return $this->mutations->run(
            $this->context($operationId),
            $retry ? 'job.retry' : 'job.cancel',
            $operationId,
            compact('id'),
            function () use ($operationId, $id, $retry): array {
                if ($retry) {
                    $this->automation->retryJob($this->context($operationId), $id);
                } else {
                    $this->automation->cancelJob($this->context($operationId), $id);
                }
                return ['updated' => true];
            }
        );
    }

    /**
     * Render the capability summary as the body of the `kumwe://capabilities` resource.
     *
     * The same document `discover()` returns, encoded for a client that reads the surface as a resource
     * rather than calling a tool. This handler checks no capability of its own, so the session's own
     * authentication is the only gate in front of it.
     *
     * @return  string  Pretty-printed JSON with unescaped slashes.
     *
     * @throws  JsonException  When the catalogue summary cannot be encoded.
     *
     * @since   2.0.0
     */
    public function capabilityResource(): string
    {
        return json_encode(
            $this->catalog->publicSummary(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Build the `kumwe_site_review` prompt for one review focus.
     *
     * The prompt only frames the request; it grants nothing, so the reviewing client is still held to whatever
     * capabilities its own credential carries when it starts reading.
     *
     * @param   string  $focus  Angle to review from: `content`, `seo`, `structure` or `extensions`.
     *
     * @return  list<array{role: string, content: string}>  A single user message naming the requested focus.
     *
     * @throws  InvalidArgumentException  When the focus is not one of the four supported values.
     *
     * @since   2.0.0
     */
    public function siteReviewPrompt(string $focus = 'content'): array
    {
        if (!in_array($focus, ['content', 'seo', 'structure', 'extensions'], true)) {
            throw new InvalidArgumentException('The site review focus is not supported.');
        }

        return [[
            'role' => 'user',
            'content' => sprintf('Review the Kumwe site with a %s focus and propose explicit changes.', $focus),
        ]];
    }

    /**
     * Discover the generated business entities visible to this MCP credential.
     *
     * @return  array{items: list<array<string, mixed>>, truncated: bool}  Bounded policy-filtered metadata.
     *
     * @throws  InsufficientCapability  When the credential cannot browse business records.
     *
     * @since   2.0.0
     */
    public function discoverBusinessRecords(): array
    {
        $this->require('business.record.browse');

        return $this->businessRecords->discover($this->context());
    }

    /**
     * Inspect one policy-visible generated business entity.
     *
     * @param   string  $definition  Definition UUID or namespaced handle.
     *
     * @return  array{definition: array<string, mixed>}  Safe typed metadata for this entity.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function inspectBusinessRecord(string $definition): array
    {
        $this->require('business.record.read');

        return $this->businessRecords->inspect($this->context(), $definition);
    }

    /**
     * Execute one typed custom view declared by a policy-visible business definition.
     *
     * The custom view kind selects its exact browse/read/create/update/history/relation policy inside the
     * shared surface service, so this adapter performs no weaker static capability shortcut beforehand.
     *
     * @param   string                $definition  Definition UUID or namespaced handle.
     * @param   string                $view        Custom view handle.
     * @param   array<string, mixed>  $query       Shared bounded record-query document.
     * @param   array<string, mixed>  $parameters  Contract-specific bounded parameters.
     * @param   ?string               $record      Optional public record identity for detail-like views.
     *
     * @return  array<string, mixed>  Policy-filtered view metadata and validated result.
     *
     * @since   2.0.0
     */
    public function executeBusinessView(
        string $definition,
        string $view,
        array $query = [],
        array $parameters = [],
        ?string $record = null,
    ): array {
        return $this->businessRecords->view(
            $this->context(),
            $definition,
            $view,
            $query,
            $parameters,
            $record,
        );
    }

    /**
     * Search one generated business entity through the shared bounded query grammar.
     *
     * @param   string                $definition  Definition UUID or namespaced handle.
     * @param   array<string, mixed>  $query       Closed filter, search, sort and projection document.
     *
     * @return  array<string, mixed>  Policy-filtered definition metadata and one bounded record page.
     *
     * @throws  InsufficientCapability  When the credential cannot browse business records.
     *
     * @since   2.0.0
     */
    public function searchBusinessRecords(string $definition, array $query = []): array
    {
        $this->require('business.record.browse');

        return $this->businessRecords->search($this->context(), $definition, $query);
    }

    /**
     * Read one generated business record by its public identity.
     *
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   bool    $includeArchived  Whether archived rows may be returned.
     * @param   bool    $includeDeleted   Whether soft-deleted rows may be returned.
     *
     * @return  array<string, mixed>  Safe definition, record and semantic fields.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function readBusinessRecord(
        string $definition,
        string $record,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): array {
        $this->require('business.record.read');

        return $this->businessRecords->read(
            $this->context(),
            $definition,
            $record,
            $includeArchived,
            $includeDeleted,
        );
    }

    /**
     * Read one bounded page of generated business record history.
     *
     * @param   string  $definition     Definition UUID or namespaced handle.
     * @param   string  $record         Public record identity.
     * @param   int     $limit          Maximum revisions, from 1 through 200.
     * @param   ?int    $beforeVersion  Exclusive positive record-version cursor.
     *
     * @return  array<string, mixed>  Omission-safe revisions and continuation metadata.
     *
     * @throws  InsufficientCapability  When the credential cannot read business record history.
     *
     * @since   2.0.0
     */
    public function businessRecordHistory(
        string $definition,
        string $record,
        int $limit = 100,
        ?int $beforeVersion = null,
    ): array {
        $this->require('business.record.history');

        return $this->businessRecords->history(
            $this->context(),
            $definition,
            $record,
            $limit,
            $beforeVersion,
        );
    }

    /**
     * Plan one atomic bulk archive, restore or declared action over at most fifty reviewed records.
     *
     * @param   string                                             $operationId  Bulk identity the plan and the
     *          eventual mutation share.
     * @param   string                                             $operation    `archive`, `restore` or `action`.
     * @param   string                                             $definition   Definition UUID or handle.
     * @param   list<array{record: string, expectedVersion: int}>  $items        Reviewed selection.
     * @param   ?string                                            $action       Bulk-enabled action handle.
     * @param   array<string, mixed>                               $input        Shared action input.
     *
     * @return  array<string, mixed>  Signed plan, binding summary and five-minute expiry.
     *
     * @throws  InsufficientCapability  When the caller lacks read or the operation's record capability.
     *
     * @since   2.0.0
     */
    public function planBusinessBulk(
        string $operationId,
        string $operation,
        string $definition,
        array $items,
        ?string $action = null,
        array $input = [],
    ): array {
        $this->require('business.record.read');
        $this->require(BusinessMcpHandlers::capabilityFor(BusinessMcpHandlers::bulkOperation($operation)));

        return $this->businessRecords->planBulk(
            $this->context(),
            $operationId,
            $operation,
            $definition,
            $items,
            $action,
            $input,
        );
    }

    /**
     * Apply one planned atomic bulk archive, restore or declared action, as the administrator bulk form does.
     *
     * @param   string                                             $operationId  Planned bulk identity.
     * @param   string                                             $plan         Signed plan for these arguments.
     * @param   string                                             $operation    `archive`, `restore` or `action`.
     * @param   string                                             $definition   Definition UUID or handle.
     * @param   list<array{record: string, expectedVersion: int}>  $items        Reviewed selection.
     * @param   ?string                                            $action       Bulk-enabled action handle.
     * @param   array<string, mixed>                               $input        Shared action input.
     *
     * @return  array<string, mixed>  Operation, count and per-member outcomes, or the identical replay.
     *
     * @throws  InsufficientCapability  When the caller lacks the operation's record capability.
     *
     * @since   2.0.0
     */
    public function executeBusinessBulk(
        string $operationId,
        string $plan,
        string $operation,
        string $definition,
        array $items,
        ?string $action = null,
        array $input = [],
    ): array {
        return $this->businessRecords->bulk(
            $this->businessMutationContext($operationId, BusinessMcpHandlers::bulkOperation($operation)),
            $operationId,
            $plan,
            $operation,
            $definition,
            $items,
            $action,
            $input,
        );
    }

    /**
     * Plan one exact generated-business mutation against current trusted state.
     *
     * Planning is read-only but requires both record read and the exact mutation capability. The shared
     * planner then derives the definition, runtime generation, record policy, actor context, payload, and
     * current source-record version bindings that execution must re-prove.
     *
     * @param   string                $operationId        Identity the plan and eventual mutation share.
     * @param   string                $operation          Closed generated-business mutation name.
     * @param   string                $definition         Definition UUID or namespaced handle.
     * @param   ?string               $record             Existing or optional create record identity.
     * @param   ?int                  $expectedVersion    Current version for an existing record.
     * @param   array<string, mixed>  $values             Create or update values.
     * @param   ?string               $relationship       Declared relationship handle.
     * @param   ?string               $target             Target record identity.
     * @param   ?int                  $position           Optional ordered relation position.
     * @param   array<string, mixed>  $targetValues       Optional owned-line values.
     * @param   list<string>          $orderedRecordIds   Complete ordered relationship member list.
     * @param   ?string               $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval UUID for execution.
     *
     * @return  array<string, mixed>  Signed five-minute plan and its safe current bindings.
     *
     * @throws  InsufficientCapability  When the credential lacks read or exact mutation capability.
     *
     * @since   2.0.0
     */
    public function planBusinessRecordMutation(
        string $operationId,
        string $operation,
        string $definition,
        ?string $record = null,
        ?int $expectedVersion = null,
        array $values = [],
        ?string $relationship = null,
        ?string $target = null,
        ?int $position = null,
        array $targetValues = [],
        array $orderedRecordIds = [],
        ?string $action = null,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        $this->require('business.record.read');
        $this->require(BusinessMcpHandlers::capabilityFor($operation));

        return $this->businessRecords->planMutation(
            $this->context(),
            $operationId,
            $operation,
            $definition,
            $record,
            $expectedVersion,
            $values,
            $relationship,
            $target,
            $position,
            $targetValues,
            $orderedRecordIds,
            $action,
            $input,
            $approvalRequestId,
        );
    }

    /**
     * Create one typed generated business record under a replay-safe identity.
     *
     * @param   string                $operationId  Caller-chosen stable operation identity.
     * @param   string                $plan         Signed plan for these exact mutation arguments.
     * @param   string                $definition   Definition UUID or namespaced handle.
     * @param   array<string, mixed>  $values       Values keyed by declared field handle.
     * @param   ?string               $record       Optional caller-chosen public identity.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function createBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        array $values,
        ?string $record = null,
    ): array {
        return $this->businessRecords->create(
            $this->businessMutationContext($operationId, 'create'),
            $operationId,
            $plan,
            $definition,
            $values,
            $record,
        );
    }

    /**
     * Update one generated business record at the exact version the caller inspected.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic version previously read.
     * @param   array<string, mixed>  $values           Replacement values by declared field handle.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function updateBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        array $values,
    ): array {
        return $this->businessRecords->update(
            $this->businessMutationContext($operationId, 'update'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $values,
        );
    }

    /**
     * Archive one generated business record at an exact optimistic version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function archiveBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->archive(
            $this->businessMutationContext($operationId, 'archive'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Restore one archived or soft-deleted generated record at an exact version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function restoreBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->restore(
            $this->businessMutationContext($operationId, 'restore'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Delete one generated business record at an exact optimistic version.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public record identity.
     * @param   int     $expectedVersion  Optimistic version previously read.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function deleteBusinessRecord(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
    ): array {
        return $this->businessRecords->delete(
            $this->businessMutationContext($operationId, 'delete'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
        );
    }

    /**
     * Create one declared relationship link or owned line.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public source-record identity.
     * @param   int                   $expectedVersion  Optimistic source version previously read.
     * @param   string                $relationship     Declared relationship handle.
     * @param   string                $target           Public target identity or new owned-line identity.
     * @param   ?int                  $position         Optional zero-based ordered position.
     * @param   array<string, mixed>  $targetValues     Values used only to create an owned line.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function relateBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
        ?int $position = null,
        array $targetValues = [],
    ): array {
        return $this->businessRecords->relate(
            $this->businessMutationContext($operationId, 'relate'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
            $position,
            $targetValues,
        );
    }

    /**
     * Remove one declared generated-record relationship link.
     *
     * @param   string  $operationId      Caller-chosen stable operation identity.
     * @param   string  $plan             Signed plan for these exact mutation arguments.
     * @param   string  $definition       Definition UUID or namespaced handle.
     * @param   string  $record           Public source-record identity.
     * @param   int     $expectedVersion  Optimistic source version previously read.
     * @param   string  $relationship     Declared relationship handle.
     * @param   string  $target           Public target identity.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function unrelateBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        string $target,
    ): array {
        return $this->businessRecords->unrelate(
            $this->businessMutationContext($operationId, 'unrelate'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $target,
        );
    }

    /**
     * Replace the complete order of one declared relationship.
     *
     * @param   string        $operationId       Caller-chosen stable operation identity.
     * @param   string        $plan              Signed plan for these exact mutation arguments.
     * @param   string        $definition        Definition UUID or namespaced handle.
     * @param   string        $record            Public source-record identity.
     * @param   int           $expectedVersion   Optimistic source version previously read.
     * @param   string        $relationship      Declared ordered relationship handle.
     * @param   list<string>  $orderedRecordIds  Complete target identities in their new order.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function reorderBusinessRecords(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $relationship,
        array $orderedRecordIds,
    ): array {
        return $this->businessRecords->reorder(
            $this->businessMutationContext($operationId, 'reorder'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $relationship,
            $orderedRecordIds,
        );
    }

    /**
     * Request independent maker-checker approval for one high-impact action attempt.
     *
     * This surface publishes no vote, approve, reject, or step-up proof method.
     *
     * @param   string                $operationId      Caller-chosen stable operation identity.
     * @param   string                $plan             Signed plan for these exact mutation arguments.
     * @param   string                $definition       Definition UUID or namespaced handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Optimistic version previously read.
     * @param   string                $action           Declared high-impact action handle.
     * @param   array<string, mixed>  $input            Typed action input, empty for current core actions.
     *
     * @return  array{approval_request_id: ?string}  Newly created approval identity.
     *
     * @since   2.0.0
     */
    public function requestBusinessRecordAction(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
    ): array {
        $result = $this->businessRecords->requestAction(
            $this->businessMutationContext($operationId, 'request_action'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $input,
        );
        $requestId = $result['approval_request_id'] ?? null;
        if (
            array_keys($result) !== ['approval_request_id']
            || ($requestId !== null && !is_string($requestId))
        ) {
            throw new InvalidArgumentException('The business-record approval result is invalid.');
        }

        return ['approval_request_id' => $requestId];
    }

    /**
     * List the generated-business approval requests exposed to MCP that the caller may see.
     *
     * The inbox the portal and REST render, narrowed to the MCP surface: any one of the approval capabilities
     * admits it and the approval query then filters each row. Decisions are not offered here.
     *
     * @param   int  $limit  Maximum requests, from 1 through 100.
     *
     * @return  array{items: list<array<string, mixed>>}  Safe request summaries.
     *
     * @throws  InsufficientCapability  When the caller holds none of the approval capabilities.
     * @throws  InvalidArgumentException  When the limit is outside its bounds.
     *
     * @since   2.0.0
     */
    public function listBusinessApprovals(int $limit = 50): array
    {
        $this->requireAny(BusinessMcpHandlers::APPROVAL_CAPABILITIES);

        return $this->businessRecords->approvals($this->context('business-approvals-list'), $limit);
    }

    /**
     * Read one generated-business approval request exposed to MCP with its redacted decisions.
     *
     * @param   string  $approval  Approval request UUID.
     *
     * @return  array<string, mixed>  Safe request summary and votes.
     *
     * @throws  InsufficientCapability  When the caller holds none of the approval capabilities.
     * @throws  \Kumwe\Approval\ApprovalDenied  When the request is not visible on MCP.
     *
     * @since   2.0.0
     */
    public function getBusinessApproval(string $approval): array
    {
        $this->requireAny(BusinessMcpHandlers::APPROVAL_CAPABILITIES);

        return $this->businessRecords->approval($this->context('business-approval-read'), $approval);
    }

    /**
     * Withdraw the caller's own pending generated-business approval request made on MCP.
     *
     * This is the requester's cancel control, never a vote: approve, reject and revoke need a fresh browser
     * step-up proof and are not published on this surface.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $approval     Approval request UUID.
     *
     * @return  array{approval_request_id: string, status: string}  The withdrawn request.
     *
     * @throws  InsufficientCapability  When the caller lacks `business.approval.request`.
     * @throws  \Kumwe\Approval\ApprovalDenied  When the request is not the caller's own pending request on MCP.
     *
     * @since   2.0.0
     */
    public function cancelBusinessApproval(string $operationId, string $approval): array
    {
        $this->require('business.approval.request');
        $this->preauthorize(
            $operationId,
            'business.approval.request',
            AuthorizationResource::item('approval_request', $approval),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business.approval.cancel',
            $operationId,
            compact('approval'),
            fn (): array => $this->businessRecords->cancelApproval($this->context($operationId), $approval),
        );
    }

    /**
     * Browse one page of the site media library exactly as the administrator media screen does.
     *
     * @param   string  $query    Case-insensitive display-name filter, at most 200 bytes.
     * @param   string  $kind     `all`, `image` or `document`.
     * @param   int     $page     One-based page.
     * @param   int     $perPage  Page size from one to ninety-six.
     *
     * @return  array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}  Page.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     * @throws  InvalidArgumentException  When the kind is unknown or media is not composed.
     *
     * @since   2.0.0
     */
    public function listMedia(string $query = '', string $kind = 'all', int $page = 1, int $perPage = 24): array
    {
        $this->require('content.read');
        if (!in_array($kind, ['all', 'image', 'document'], true)) {
            throw new InvalidArgumentException('The media kind must be all, image or document.');
        }
        $result = $this->mediaLibrary()->browse($this->context(), $query, $kind, $page, $perPage);

        return [
            'items' => array_map(static fn (MediaAsset $asset): array => $asset->toArray(), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => $result->pages(),
            'per_page' => $result->perPage,
        ];
    }

    /**
     * Read one media library asset's metadata.
     *
     * @param   string  $media  Asset identifier.
     *
     * @return  array<string, mixed>  Asset metadata.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     * @throws  ContentNotFound  When the library holds no such asset, answered as `resource.not_found`.
     *
     * @since   2.0.0
     */
    public function getMedia(string $media): array
    {
        $this->require('content.read');

        return ($this->mediaLibrary()->get($this->context(), $media) ?? throw new ContentNotFound($media))
            ->toArray();
    }

    /**
     * Store one base64-encoded file in the media library under a replay-safe operation identity.
     *
     * The decoded bytes are staged in an owner-only temporary file the storage copies from, and removed
     * afterwards. The operation is fenced on the file name and a digest of the content, so the payload itself
     * is never recorded with the claim.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $filename     Client file name the asset is stored under.
     * @param   string  $content      Base64-encoded file content.
     *
     * @return  array<string, mixed>  The stored, audited asset, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     * @throws  InvalidArgumentException  When the content is not base64 or the service refuses the file.
     *
     * @since   2.0.0
     */
    public function uploadMedia(string $operationId, string $filename, string $content): array
    {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::collection('media'));
        $bytes = base64_decode($content, true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('The media content must be non-empty base64.');
        }

        return $this->mutations->run(
            $this->context($operationId),
            'media.upload',
            $operationId,
            ['filename' => $filename, 'content_sha256' => hash('sha256', $bytes)],
            function () use ($operationId, $filename, $bytes): array {
                $staged = tempnam(sys_get_temp_dir(), 'kumwe-mcp-media-');
                if (!is_string($staged)) {
                    throw new \RuntimeException('The media upload could not be staged.');
                }
                try {
                    if (!chmod($staged, 0o600) || file_put_contents($staged, $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('The media upload could not be staged.');
                    }

                    return $this->mediaLibrary()->upload($this->context($operationId), $staged, $filename)
                        ->toArray();
                } finally {
                    if (is_file($staged)) {
                        unlink($staged);
                    }
                }
            },
        );
    }

    /**
     * Remove one asset from the media library under a replay-safe operation identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $media        Asset identifier; one already gone is not an error.
     *
     * @return  array{id: string, deleted: true}  Confirmation.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.delete`.
     *
     * @since   2.0.0
     */
    public function deleteMedia(string $operationId, string $media): array
    {
        $this->require('content.delete');
        $this->preauthorize($operationId, 'content.delete', AuthorizationResource::collection('media'));

        return $this->mutations->run(
            $this->context($operationId),
            'media.delete',
            $operationId,
            compact('media'),
            function () use ($operationId, $media): array {
                $this->mediaLibrary()->delete($this->context($operationId), $media);

                return ['id' => $media, 'deleted' => true];
            },
        );
    }

    /**
     * List the stored wording overrides of one administered layer, as the Wording screen does.
     *
     * @param   string   $layer   `site`, or `organization` for the credential's membership organization.
     * @param   ?string  $locale  Restrict to one carried locale, or null for every locale.
     *
     * @return  array{layer: string, locale: ?string, items: list<array<string, mixed>>}  Overrides.
     *
     * @throws  InsufficientCapability  When the caller lacks `localization.overrides.manage`.
     * @throws  InvalidArgumentException  When the layer is not administered or the locale is not carried.
     *
     * @since   2.0.0
     */
    public function listWordingOverrides(string $layer = 'site', ?string $locale = null): array
    {
        $this->require('localization.overrides.manage');
        $administered = self::wordingLayer($layer);

        return [
            'layer' => $administered->value,
            'locale' => $locale,
            'items' => array_map(
                static fn (MessageOverrideRecord $record): array => $record->toArray(),
                $this->wordingOverrides()->overrides($this->context(), $administered, $locale),
            ),
        ];
    }

    /**
     * Search the shipped wording of one carried locale an override starts from.
     *
     * @param   string  $locale  Carried locale tag.
     * @param   string  $query   Case-insensitive identifier or wording substring; empty for the first page.
     * @param   int     $limit   Matches to return, from one to two hundred.
     *
     * @return  array{locale: string, items: list<array{identifier: string, pattern: string, layer: string}>}  Hits.
     *
     * @throws  InsufficientCapability  When the caller lacks `localization.overrides.manage`.
     * @throws  InvalidArgumentException  When the locale is not carried.
     *
     * @since   2.0.0
     */
    public function searchWordingCatalogue(string $locale, string $query = '', int $limit = 50): array
    {
        $this->require('localization.overrides.manage');

        return [
            'locale' => $locale,
            'items' => $this->wordingOverrides()->searchCatalogue($this->context(), $locale, $query, $limit),
        ];
    }

    /**
     * Store one wording override under a replay-safe operation identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $layer        Administered layer, `site` or `organization`.
     * @param   string  $locale       Carried locale tag.
     * @param   string  $identifier   Message identifier whose wording is replaced.
     * @param   string  $pattern      Replacement ICU pattern.
     *
     * @return  array<string, mixed>  The stored override, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `localization.overrides.manage`.
     * @throws  InvalidArgumentException  When the layer, locale, identifier or ICU pattern is refused.
     *
     * @since   2.0.0
     */
    public function saveWordingOverride(
        string $operationId,
        string $layer,
        string $locale,
        string $identifier,
        string $pattern,
    ): array {
        $this->require('localization.overrides.manage');
        $this->preauthorize(
            $operationId,
            'localization.overrides.manage',
            AuthorizationResource::collection('message_override'),
        );
        $administered = self::wordingLayer($layer);

        return $this->mutations->run(
            $this->context($operationId),
            'wording.override.save',
            $operationId,
            compact('layer', 'locale', 'identifier', 'pattern'),
            function () use ($operationId, $administered, $locale, $identifier, $pattern): array {
                try {
                    return $this->wordingOverrides()->override(
                        $this->context($operationId),
                        $administered,
                        $locale,
                        $identifier,
                        $pattern,
                    )->toArray();
                } catch (MessageFormattingFailed $refused) {
                    // ICU refusals are the caller's input, answered as `request.invalid` like REST's 422.
                    throw new InvalidArgumentException($refused->getMessage(), 0, $refused);
                }
            },
        );
    }

    /**
     * Withdraw one wording override under a replay-safe operation identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $layer        Administered layer, `site` or `organization`.
     * @param   string  $locale       Carried locale tag.
     * @param   string  $identifier   Message identifier to stop overriding.
     *
     * @return  array{withdrawn: bool}  Whether an override was withdrawn.
     *
     * @throws  InsufficientCapability  When the caller lacks `localization.overrides.manage`.
     * @throws  InvalidArgumentException  When the layer, locale or identifier is refused.
     *
     * @since   2.0.0
     */
    public function withdrawWordingOverride(
        string $operationId,
        string $layer,
        string $locale,
        string $identifier,
    ): array {
        $this->require('localization.overrides.manage');
        $this->preauthorize(
            $operationId,
            'localization.overrides.manage',
            AuthorizationResource::collection('message_override'),
        );
        $administered = self::wordingLayer($layer);

        return $this->mutations->run(
            $this->context($operationId),
            'wording.override.withdraw',
            $operationId,
            compact('layer', 'locale', 'identifier'),
            fn (): array => ['withdrawn' => $this->wordingOverrides()->withdraw(
                $this->context($operationId),
                $administered,
                $locale,
                $identifier,
            )],
        );
    }

    /**
     * Read the Business Security overview the Business Security screen renders.
     *
     * The screen's writes are not published: `BusinessSecurityAdministrationService` consumes a fresh human
     * step-up proof for each of them, which an MCP credential cannot hold.
     *
     * @return  array<string, list<array<string, mixed>>>  Organizations, workspaces, memberships, policies,
     *          separation-of-duty rules and approvals scoped to the credential's site and membership.
     *
     * @throws  InsufficientCapability  When the caller lacks `business.security.manage`.
     * @throws  InvalidArgumentException  When the server was composed without the read model.
     *
     * @since   2.0.0
     */
    public function businessSecurityOverview(): array
    {
        $this->require('business.security.manage');
        $security = $this->businessSecurity
            ?? throw new InvalidArgumentException('Business Security is unavailable on this server.');

        return $security->overview($this->context());
    }

    /**
     * Read one content entry, trashed entries included, as the editor opens it.
     *
     * @param   string  $id  Entry UUID.
     *
     * @return  array<string, mixed>  The stored record.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     * @throws  ContentNotFound  When the site holds no such entry.
     *
     * @since   2.0.0
     */
    public function getContent(string $id): array
    {
        $this->require('content.read');

        return $this->content->get($this->context(), $id, true)->toArray();
    }

    /**
     * Read one menu.
     *
     * @param   string  $id  Menu UUID.
     *
     * @return  array<string, mixed>  The stored menu.
     *
     * @throws  InsufficientCapability  When the caller lacks `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function getMenu(string $id): array
    {
        $this->require('navigation.manage');

        return $this->navigation->menu($this->context(), $id)->toArray();
    }

    /**
     * Rename one menu at an expected version under a replay-safe operation identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           Menu UUID.
     * @param   int     $version      Version the caller last read.
     * @param   string  $handle       New menu handle.
     * @param   string  $title        New menu title.
     *
     * @return  array<string, mixed>  The stored menu, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function updateMenu(string $operationId, string $id, int $version, string $handle, string $title): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'menu.update',
            $operationId,
            compact('id', 'version', 'handle', 'title'),
            fn (): array => $this->navigation->updateMenu(
                $this->context($operationId),
                $id,
                $version,
                $handle,
                $title,
            )->toArray(),
        );
    }

    /**
     * Delete one menu at an expected version under a replay-safe operation identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $id           Menu UUID.
     * @param   int     $version      Version the caller last read.
     *
     * @return  array{deleted: true}  Confirmation, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `navigation.manage`.
     *
     * @since   2.0.0
     */
    public function deleteMenu(string $operationId, string $id, int $version): array
    {
        $this->require('navigation.manage');
        $this->preauthorize($operationId, 'navigation.manage', AuthorizationResource::item('menu', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'menu.delete',
            $operationId,
            compact('id', 'version'),
            function () use ($operationId, $id, $version): array {
                $this->navigation->deleteMenu($this->context($operationId), $id, $version);

                return ['deleted' => true];
            },
        );
    }

    /**
     * List the site's content types, as the content models screen does.
     *
     * @return  array{items: list<array<string, mixed>>}  Content types.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function listContentTypes(): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            static fn (ContentTypeDefinition $type): array => $type->toArray(),
            $this->contentModels()->contentTypes($this->context()),
        )];
    }

    /**
     * Read one content type by handle or UUID, optionally at an exact version.
     *
     * @param   string  $id       Handle or UUID.
     * @param   ?int    $version  Exact version, or null for the current one.
     *
     * @return  array<string, mixed>  The content type.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function getContentType(string $id, ?int $version = null): array
    {
        $this->require('content.read');

        return $this->contentModels()->contentType($this->context(), $id, $version)->toArray();
    }

    /**
     * Create one content type under a replay-safe operation identity.
     *
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   string                $handle       New type handle.
     * @param   string                $name         Display name.
     * @param   string                $workflow     Workflow handle or UUID the type is bound to.
     * @param   array<string, mixed>  $schema       Field schema document.
     *
     * @return  array<string, mixed>  The stored type, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function createContentType(
        string $operationId,
        string $handle,
        string $name,
        string $workflow,
        array $schema = [],
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::collection('content_type'));

        return $this->mutations->run(
            $this->context($operationId),
            'content-type.create',
            $operationId,
            compact('handle', 'name', 'workflow', 'schema'),
            fn (): array => $this->contentModels()->createContentType(
                $this->context($operationId),
                $handle,
                $name,
                $workflow,
                $schema,
            )->toArray(),
        );
    }

    /**
     * Publish a new version of one content type at an expected version under a replay-safe identity.
     *
     * @param   string                $operationId    Idempotency key this write is fenced on.
     * @param   string                $id             Type UUID.
     * @param   int                   $version        Version the caller last read.
     * @param   string                $name           Display name.
     * @param   string                $workflow       Workflow handle or UUID.
     * @param   array<string, mixed>  $schema         Field schema document.
     * @param   bool                  $allowBreaking  Whether a breaking schema change is accepted.
     *
     * @return  array<string, mixed>  The stored type, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function updateContentType(
        string $operationId,
        string $id,
        int $version,
        string $name,
        string $workflow,
        array $schema = [],
        bool $allowBreaking = false,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::item('content_type', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'content-type.update',
            $operationId,
            compact('id', 'version', 'name', 'workflow', 'schema', 'allowBreaking'),
            fn (): array => $this->contentModels()->updateContentType(
                $this->context($operationId),
                $id,
                $version,
                $name,
                $workflow,
                $schema,
                $allowBreaking,
            )->toArray(),
        );
    }

    /**
     * List the site's workflows, as the content models screen does.
     *
     * @return  array{items: list<array<string, mixed>>}  Workflows.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function listWorkflows(): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            static fn (WorkflowDefinition $workflow): array => $workflow->toArray(),
            $this->contentModels()->workflows($this->context()),
        )];
    }

    /**
     * Read one workflow by handle or UUID, optionally at an exact version.
     *
     * @param   string  $id       Handle or UUID.
     * @param   ?int    $version  Exact version, or null for the current one.
     *
     * @return  array<string, mixed>  The workflow.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read`.
     *
     * @since   2.0.0
     */
    public function getWorkflow(string $id, ?int $version = null): array
    {
        $this->require('content.read');

        return $this->contentModels()->workflow($this->context(), $id, $version)->toArray();
    }

    /**
     * Create one workflow under a replay-safe operation identity.
     *
     * @param   string                      $operationId  Idempotency key this write is fenced on.
     * @param   string                      $handle       New workflow handle.
     * @param   string                      $name         Display name.
     * @param   list<array<string, mixed>>  $states       Declared states.
     * @param   list<array<string, mixed>>  $transitions  Declared transitions.
     *
     * @return  array<string, mixed>  The stored workflow, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function createWorkflow(
        string $operationId,
        string $handle,
        string $name,
        array $states,
        array $transitions,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::collection('workflow'));

        return $this->mutations->run(
            $this->context($operationId),
            'workflow.create',
            $operationId,
            compact('handle', 'name', 'states', 'transitions'),
            fn (): array => $this->contentModels()->createWorkflow(
                $this->context($operationId),
                $handle,
                $name,
                $states,
                $transitions,
            )->toArray(),
        );
    }

    /**
     * Publish a new version of one workflow at an expected version under a replay-safe identity.
     *
     * @param   string                      $operationId    Idempotency key this write is fenced on.
     * @param   string                      $id             Workflow UUID.
     * @param   int                         $version        Version the caller last read.
     * @param   string                      $name           Display name.
     * @param   list<array<string, mixed>>  $states         Declared states.
     * @param   list<array<string, mixed>>  $transitions    Declared transitions.
     * @param   bool                        $allowBreaking  Whether a breaking change is accepted.
     *
     * @return  array<string, mixed>  The stored workflow, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function updateWorkflow(
        string $operationId,
        string $id,
        int $version,
        string $name,
        array $states,
        array $transitions,
        bool $allowBreaking = false,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::item('workflow', $id));

        return $this->mutations->run(
            $this->context($operationId),
            'workflow.update',
            $operationId,
            compact('id', 'version', 'name', 'states', 'transitions', 'allowBreaking'),
            fn (): array => $this->contentModels()->updateWorkflow(
                $this->context($operationId),
                $id,
                $version,
                $name,
                $states,
                $transitions,
                $allowBreaking,
            )->toArray(),
        );
    }

    /**
     * Save one business definition document as the working draft under a replay-safe identity.
     *
     * @param   string                $operationId       Idempotency key this write is fenced on.
     * @param   array<string, mixed>  $definition        Definition document.
     * @param   ?int                  $expectedRevision  Draft revision the caller last read, or null for a new one.
     *
     * @return  array<string, mixed>  The stored draft, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function saveBusinessDefinitionDraft(
        string $operationId,
        array $definition,
        ?int $expectedRevision = null,
    ): array {
        $this->require('content.update');
        $this->preauthorize(
            $operationId,
            'content.update',
            AuthorizationResource::collection('business_definition'),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_definition.draft.save',
            $operationId,
            compact('definition', 'expectedRevision'),
            fn (): array => self::definitionDraft($this->definitions->importDraft(
                $this->context($operationId),
                $definition,
                $expectedRevision,
            )),
        );
    }

    /**
     * Validate the working draft of one definition, as the definitions screen's validate control does.
     *
     * Validation is audited, so it is fenced like a write.
     *
     * @param   string  $operationId  Idempotency key this call is fenced on.
     * @param   string  $handle       Definition handle or UUID.
     *
     * @return  array<string, mixed>  The validated draft, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function validateBusinessDefinitionDraft(string $operationId, string $handle): array
    {
        $this->require('content.update');
        $this->preauthorize(
            $operationId,
            'content.update',
            AuthorizationResource::collection('business_definition'),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_definition.validate',
            $operationId,
            compact('handle'),
            fn (): array => self::definitionDraft($this->definitions->validateDraft(
                $this->context($operationId),
                $handle,
            )),
        );
    }

    /**
     * Mark one published definition version superseded under a replay-safe identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $handle       Definition handle or UUID.
     * @param   int     $version      Published version.
     *
     * @return  array<string, mixed>  The version record, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function supersedeBusinessDefinition(string $operationId, string $handle, int $version): array
    {
        return $this->retireBusinessDefinition($operationId, 'supersede', $handle, $version);
    }

    /**
     * Mark one published definition version deprecated under a replay-safe identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $handle       Definition handle or UUID.
     * @param   int     $version      Published version.
     *
     * @return  array<string, mixed>  The version record, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function deprecateBusinessDefinition(string $operationId, string $handle, int $version): array
    {
        return $this->retireBusinessDefinition($operationId, 'deprecate', $handle, $version);
    }

    /**
     * Withdraw one published definition version so the runtime refuses it, under a replay-safe identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $handle       Definition handle or UUID.
     * @param   int     $version      Published version.
     *
     * @return  array<string, mixed>  The version record, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    public function rejectBusinessDefinition(string $operationId, string $handle, int $version): array
    {
        return $this->retireBusinessDefinition($operationId, 'reject', $handle, $version);
    }

    /**
     * Read one record with exactly one declared relationship hydrated, as the relationship screen does.
     *
     * @param   string  $definition       Definition UUID or handle.
     * @param   string  $record           Public record identity.
     * @param   string  $relationship     Declared relationship handle.
     * @param   bool    $includeArchived  Whether an archived source may be addressed.
     * @param   bool    $includeDeleted   Whether a soft-deleted source may be addressed.
     *
     * @return  array<string, mixed>  Safe detail model with the one relationship.
     *
     * @throws  InsufficientCapability  When the caller lacks `business.record.read`.
     *
     * @since   2.0.0
     */
    public function readBusinessRelationship(
        string $definition,
        string $record,
        string $relationship,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): array {
        $this->require('business.record.read');

        return $this->businessRecords->relationship(
            $this->context(),
            $definition,
            $record,
            $relationship,
            $includeArchived,
            $includeDeleted,
        );
    }

    /**
     * Read the Blueprint composition of one Content type version, as the composition screen does.
     *
     * @param   string  $contentType  Content type UUID.
     * @param   int     $version      Published Content type version.
     *
     * @return  array<string, mixed>  Coordinates, model, binding and the exact Blueprint head.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read` or `studio.mode.blueprint`.
     * @throws  ContentModelNotFound  When no composition is provisioned or the model is unavailable, answered as
     *          `resource.not_found`.
     * @throws  \DomainException  When the Blueprint is locked to another published theme.
     *
     * @since   2.0.0
     */
    public function getStudioComposition(string $contentType, int $version): array
    {
        $this->require('studio.mode.blueprint');
        $this->require('content.read');

        return $this->studioComposition(
            $contentType,
            $version,
            fn (StudioContentCompositionService $compositions): ?StudioContentComposition => $compositions->find(
                $this->context(),
                $contentType,
                $version,
            ),
        );
    }

    /**
     * Provision the empty Blueprint draft of one Content type version under a replay-safe identity.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $contentType  Content type UUID.
     * @param   int     $version      Published Content type version.
     *
     * @return  array<string, mixed>  The provisioned composition, the one already bound, or the stored copy.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.read` or `studio.mode.blueprint`.
     * @throws  ContentModelNotFound  When the model is unavailable, answered as `resource.not_found`.
     * @throws  \DomainException  When the Blueprint is locked to another published theme.
     *
     * @since   2.0.0
     */
    public function provisionStudioComposition(string $operationId, string $contentType, int $version): array
    {
        $this->require('studio.mode.blueprint');
        $this->require('content.read');
        $this->preauthorize($operationId, 'content.read', AuthorizationResource::item('content_type', $contentType));

        return $this->mutations->run(
            $this->context($operationId),
            'studio.composition.provision',
            $operationId,
            compact('contentType', 'version'),
            fn (): array => $this->studioComposition(
                $contentType,
                $version,
                fn (StudioContentCompositionService $service): StudioContentComposition => $service->provision(
                    $this->context($operationId),
                    $contentType,
                    $version,
                    StudioContentCompositionService::RENDERERS,
                ),
            ),
        );
    }

    /**
     * Execute one ordinary declared action; a high-impact attempt fails closed without browser step-up.
     *
     * @param   string                $operationId        Caller-chosen stable operation identity.
     * @param   string                $plan               Signed plan for these exact mutation arguments.
     * @param   string                $definition         Definition UUID or namespaced handle.
     * @param   string                $record             Public record identity.
     * @param   int                   $expectedVersion    Optimistic version previously read.
     * @param   string                $action             Declared action handle.
     * @param   array<string, mixed>  $input              Typed action input.
     * @param   ?string               $approvalRequestId  Independent approval UUID when required.
     *
     * @return  array<string, mixed>  Omission-safe mutation result or an identical replay.
     *
     * @since   2.0.0
     */
    public function executeBusinessRecordAction(
        string $operationId,
        string $plan,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array {
        return $this->businessRecords->executeAction(
            $this->businessMutationContext($operationId, 'execute_action'),
            $operationId,
            $plan,
            $definition,
            $record,
            $expectedVersion,
            $action,
            $input,
            $approvalRequestId,
        );
    }

    /**
     * Inspect a completed generated-business mutation owned by this exact actor and policy context.
     *
     * @param   string  $operationId  Identity used for the original generated-business mutation.
     *
     * @return  array<string, mixed>  Caller-bound status and omission-safe mutation result.
     *
     * @throws  InsufficientCapability  When the credential cannot read business records.
     *
     * @since   2.0.0
     */
    public function businessRecordOperationStatus(string $operationId): array
    {
        $this->require('business.record.read');

        return $this->businessRecords->operationStatus($this->context(), $operationId);
    }

    /**
     * Business definition and schema tools.
     *
     * These read and drive exactly the services the REST routes and console commands use.
     * Composing a destructive purge plan is deliberately absent: it requires re-proving a
     * current password, which an agent surface must not be able to satisfy.
     */

    /**
     * List where every business entity definition in this site stands.
     *
     * The catalogue heads are flattened into plain rows here, so one call answers the question an agent
     * actually has. A non-zero `draft_revision` means unpublished work is waiting and is the token the next
     * write has to quote; a null `published_version` means the handle has never served anything.
     *
     * @return  array{items: list<array<string, mixed>>}  One row per handle under `items`, carrying its
     *          identifier, handle, site, owner and owner liveness, draft revision, published version and status.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listBusinessDefinitions(): array
    {
        $this->require('content.read');
        $items = [];
        foreach ($this->definitions->catalog($this->context()) as $entry) {
            $items[] = [
                'id' => $entry->id,
                'handle' => $entry->handle,
                'site' => $entry->siteIdentifier,
                'owner' => $entry->owner->toArray(),
                'owner_active' => $entry->ownerActive,
                'draft_revision' => $entry->draftRevision,
                'published_version' => $entry->publishedVersion,
                'status' => $entry->status->value,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Read one published version of a business entity definition.
     *
     * @param   string  $handle   The definition's handle, or its UUID.
     * @param   ?int    $version  Published version to load, or null for the one the catalogue head serves.
     *
     * @return  array<string, mixed>  The definition document with its version, status, checksum, publisher,
     *          publication instant and the compatibility plan that produced it.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function getBusinessDefinition(string $handle, ?int $version = null): array
    {
        $this->require('content.read');

        return $this->definitionVersion($this->definitions->published($this->context(), $handle, $version));
    }

    /**
     * Read the working draft of a business entity definition.
     *
     * The draft is where unpublished edits live, and its revision is the number `publishBusinessDefinition()`
     * has to be given, so this is the call that precedes a publication.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array<string, mixed>  The draft's revision, checksum, last editor and edit instant, plus the
     *          definition document itself under `definition`.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function getBusinessDefinitionDraft(string $handle): array
    {
        $this->require('content.read');

        return self::definitionDraft($this->definitions->draft($this->context(), $handle));
    }

    /**
     * List every version of one definition that was ever published.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array{items: list<array<string, mixed>>}  Versions under `items`, newest first; empty when the
     *          definition exists but has never been published.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function listBusinessDefinitionHistory(string $handle): array
    {
        $this->require('content.read');

        return ['items' => array_map(
            $this->definitionVersion(...),
            $this->definitions->history($this->context(), $handle),
        )];
    }

    /**
     * Price what publishing the current draft would do, without recording that the question was asked.
     *
     * Read-only and unaudited, so an agent may ask it freely before deciding whether publication is safe. The
     * plan it returns is what `publishBusinessDefinition()` would demand confirmation for.
     *
     * @param   string  $handle  The definition's handle, or its UUID.
     *
     * @return  array<string, mixed>  Every classified difference between the published head and the draft.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.read`.
     *
     * @since   2.0.0
     */
    public function previewBusinessDefinitionCompatibility(string $handle): array
    {
        $this->require('content.read');

        return $this->definitions->previewDraft($this->context(), $handle)->toArray();
    }

    /**
     * Publish the working draft as a new immutable definition version.
     *
     * The revision is the concurrency check: publication is refused if the draft moved after the caller read
     * it, so an agent cannot publish edits it never saw. A plan carrying breaking changes is refused as well
     * unless `$confirmed` is set, which is the point at which a client must have shown the compatibility
     * preview to whoever is accountable for it.
     *
     * @param   string  $operationId       Idempotency key this write is fenced on.
     * @param   string  $handle            The definition's handle, or its UUID.
     * @param   int     $expectedRevision  Draft revision being published, as the caller last read it.
     * @param   bool    $confirmed         Whether the caller accepts a plan that carries breaking changes.
     *
     * @return  array<string, mixed>  The stored version with its checksum, publisher, publication instant and
     *          compatibility plan.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `content.update`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `content.update` on the
     *          business definition collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function publishBusinessDefinition(
        string $operationId,
        string $handle,
        int $expectedRevision,
        bool $confirmed = false,
    ): array {
        $this->require('content.update');
        $this->preauthorize($operationId, 'content.update', AuthorizationResource::collection('business_definition'));

        return $this->mutations->run(
            $this->context($operationId),
            'business_definition.publish',
            $operationId,
            ['handle' => $handle, 'expectedRevision' => $expectedRevision, 'confirmed' => $confirmed],
            fn (): array => $this->definitionVersion($this->definitions->publish(
                $this->context($operationId),
                $handle,
                $expectedRevision,
                $confirmed,
            )),
        );
    }

    /**
     * List the published definitions a schema plan can be compiled for.
     *
     * @return  array{items: list<array<string, mixed>>}  Plannable definitions under `items`, each with its
     *          identifier, handle, version and owner.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     *
     * @since   2.0.0
     */
    public function listSchemaDefinitions(): array
    {
        $this->require('business.schema.read');

        return ['items' => $this->schema->definitions($this->context())];
    }

    /**
     * List this site's schema plans, each with the checksum an approval has to quote.
     *
     * @return  array{items: list<array<string, mixed>>}  Plans under `items`, most recently created first.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When a plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function listSchemaPlans(): array
    {
        $this->require('business.schema.read');

        return ['items' => array_map($this->schemaPlan(...), $this->schema->plans($this->context()))];
    }

    /**
     * Read one schema plan together with its durable step journal.
     *
     * The journal is what makes an interrupted execution recoverable: it records which operations actually
     * landed, so read it before deciding whether `recoverSchemaPlan()` is the right next call.
     *
     * @param   string  $planId  UUID of the plan to read.
     *
     * @return  array<string, mixed>  The plan and its checksum, plus one `steps` entry per operation in
     *          ordinal order.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.read`.
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    public function getSchemaPlan(string $planId): array
    {
        $this->require('business.schema.read');
        $context = $this->context();

        return [
            ...$this->schemaPlan($this->schema->plan($context, $planId)),
            'steps' => array_map(
                static fn (SchemaPlanStep $step): array => $step->toArray(),
                $this->schema->steps($context, $planId),
            ),
        ];
    }

    /**
     * Compile a deterministic schema plan for a published definition.
     *
     * Runs no DDL. It records what would be done and returns the checksum an approver has to quote back, which
     * is what separates inspecting a change from authorising it.
     *
     * @param   string  $operationId   Idempotency key this write is fenced on.
     * @param   string  $definitionId  UUID of the published definition to plan against.
     *
     * @return  array<string, mixed>  The proposed plan, carrying the checksum an approval must match.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.plan`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `business.schema.plan` on
     *          the schema collection.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function createSchemaPlan(string $operationId, string $definitionId): array
    {
        $this->require('business.schema.plan');
        $this->preauthorize($operationId, 'business.schema.plan', AuthorizationResource::collection('business_schema'));

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.plan',
            $operationId,
            ['definitionId' => $definitionId],
            fn (): array => $this->schemaPlan($this->schema->createPlan($this->context($operationId), $definitionId)),
        );
    }

    /**
     * Approve the exact plan that was inspected, identified by its checksum.
     *
     * The service's confirmation argument is deliberately never passed from here. Anything riskier than an
     * online-safe-additive plan has to quote its checksum a second time as a confirmation digested against
     * the approver's own authorization fingerprint, and this surface supplies none — so a high-impact plan
     * fails closed at this call rather than becoming approvable by anything holding a token. The expected
     * checksum is the other half: an approval is refused outright if the plan moved after it was read.
     *
     * @param   string   $operationId         Idempotency key this write is fenced on.
     * @param   string   $planId              UUID of the plan being approved.
     * @param   string   $expectedChecksum    Checksum of the plan as inspected; a mismatch refuses the approval.
     * @param   ?string  $recoveryEvidenceId  Recovery drill a rebuilding or destructive plan is approved on
     *          the strength of; null when the plan needs none, and naming one a plan does not need is refused.
     *
     * @return  array<string, mixed>  The plan in its approved state, at the revision the approval wrote.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.approve`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `business.schema.approve`,
     *          or `business.schema.destructive` for a destructive plan, on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function approveSchemaPlan(
        string $operationId,
        string $planId,
        string $expectedChecksum,
        ?string $recoveryEvidenceId = null,
    ): array {
        $this->require('business.schema.approve');
        $this->preauthorize(
            $operationId,
            'business.schema.approve',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        // No confirmation is passed: a high-impact plan needs a re-proved password, which
        // this surface cannot supply, so such a plan fails closed here by design.
        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.approve',
            $operationId,
            ['planId' => $planId, 'expectedChecksum' => $expectedChecksum],
            fn (): array => $this->schemaPlan($this->schema->approve(
                $this->context($operationId),
                $planId,
                $expectedChecksum,
                null,
                $recoveryEvidenceId,
            )),
        );
    }

    /**
     * Apply an approved schema plan to the physical tables.
     *
     * This is the call that changes physical tables. Ordinary failures propagate untouched; the one case the
     * service handles itself is a first-time set of definitions that reference each other, where the initial
     * plan pauses on a peer's table that does not exist yet and the connected peers are executed or resumed
     * before the requested plan is. What lands is journalled step by step, which is what leaves an interrupted
     * run recoverable through `recoverSchemaPlan()` rather than merely broken.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $planId       UUID of the approved plan to execute.
     *
     * @return  array<string, mixed>  The outcome: the fence taken, the completed and skipped step counts, and
     *          the resulting schema checksum.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.execute`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `business.schema.execute`,
     *          or `business.schema.destructive` for a destructive plan, on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function executeSchemaPlan(string $operationId, string $planId): array
    {
        $this->require('business.schema.execute');
        $this->preauthorize(
            $operationId,
            'business.schema.execute',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.execute',
            $operationId,
            ['planId' => $planId],
            fn (): array => $this->schema->execute($this->context($operationId), $planId)->toArray(),
        );
    }

    /**
     * Resume or reconcile a schema plan whose execution was interrupted.
     *
     * Recovery reads the journal rather than starting over: operations already recorded as landed are skipped,
     * so re-running is safe. A plan that was never interrupted is refused, which stops this being used as a
     * second execute.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $planId       UUID of the executing, failed or recovery-required plan.
     *
     * @return  array<string, mixed>  The same outcome shape as a first run, marked as resumed.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it does not hold `business.schema.recover`.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses `business.schema.recover`
     *          on this plan.
     * @throws  InvalidArgumentException  When the operation identifier is malformed, or was already used for this
     *          operation with different arguments.
     * @throws  \RuntimeException  When another attempt still holds the lease on this identifier, or the lease is lost
     *          before the write completes.
     *
     * @since   2.0.0
     */
    public function recoverSchemaPlan(string $operationId, string $planId): array
    {
        $this->require('business.schema.recover');
        $this->preauthorize(
            $operationId,
            'business.schema.recover',
            AuthorizationResource::item('business_schema_plan', $planId),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_schema.recover',
            $operationId,
            ['planId' => $planId],
            fn (): array => $this->schema->recover($this->context($operationId), $planId)->toArray(),
        );
    }

    /**
     * Flatten a published definition version into the map the definition tools return.
     *
     * One projection shared by the read, history and publish tools, so a client sees the same keys whichever
     * of them produced the version.
     *
     * @param   DefinitionVersionRecord  $record  Stored version with its compatibility plan and publisher.
     *
     * @return  array<string, mixed>  Version number, status, checksum, publisher, publication instant,
     *          compatibility plan and the definition document itself.
     *
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the definition cannot be
     *          canonically encoded, so no checksum can be computed for it.
     *
     * @since   2.0.0
     */
    private function definitionVersion(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'compatibility' => $record->compatibility->toArray(),
            'definition' => $record->definition->toArray(),
        ];
    }

    /**
     * Serialise a plan together with the checksum an approval has to quote back.
     *
     * The checksum is not a stored column: it is recomputed from the canonical form on every read, which is
     * what lets a client prove that what it approves is byte-for-byte what it inspected.
     *
     * @param   SchemaPlan  $plan  Plan to serialise.
     *
     * @return  array<string, mixed>  The plan's own fields plus its `checksum`.
     *
     * @throws  \Kumwe\BusinessDefinition\Domain\InvalidBusinessDefinition  When the plan holds more than 512
     *          operations, which the canonical encoder refuses to fingerprint.
     *
     * @since   2.0.0
     */
    private function schemaPlan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }

    /**
     * Execute one active contributed report through the shared policy-filtered report service.
     *
     * @param   string                $report      Namespaced active report identifier.
     * @param   array<string, mixed>  $parameters  Typed values keyed by declared parameter name.
     *
     * @return  array<string, mixed>  Bounded omission-safe report result.
     *
     * @since   2.0.0
     */
    public function executeBusinessReport(string $report, array $parameters = []): array
    {
        $this->require('business.record.report');

        return $this->businessReports->execute($this->context(), $report, $parameters);
    }

    /**
     * List active contributed reports visible to the bound MCP credential.
     *
     * @return  array{items: list<array<string, mixed>>}  Safe typed report summaries.
     *
     * @since   2.0.0
     */
    public function listBusinessReports(): array
    {
        $this->require('business.record.report');

        return $this->businessReports->list($this->context());
    }

    /**
     * Idempotently create one durable report export under the caller's exact authority snapshot.
     *
     * @param   string                $operationId       Stable MCP idempotency identity.
     * @param   string                $report            Namespaced active report identifier.
     * @param   array<string, mixed>  $parameters        Typed values keyed by declared parameter name.
     * @param   int                   $retentionSeconds  Artifact lifetime from one minute through seven days.
     *
     * @return  array<string, mixed>  Queued export lifecycle metadata or its replay.
     *
     * @since   2.0.0
     */
    public function requestBusinessReportExport(
        string $operationId,
        string $report,
        array $parameters = [],
        int $retentionSeconds = 86_400,
    ): array {
        $this->require('business.record.export');
        $this->preauthorize(
            $operationId,
            'business.record.export',
            AuthorizationResource::collection('business_report'),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business.report.export.request',
            $operationId,
            compact('report', 'parameters', 'retentionSeconds'),
            fn (): array => $this->businessReports->requestExport(
                $this->context($operationId),
                $report,
                $parameters,
                $retentionSeconds,
            ),
        );
    }

    /**
     * Read current authorized lifecycle metadata for one export.
     *
     * @param   string  $artifact  Export artifact UUID.
     *
     * @return  array<string, mixed>  Current safe export status.
     *
     * @since   2.0.0
     */
    public function businessReportExportStatus(string $artifact): array
    {
        $this->require('business.record.export');

        return $this->businessReports->exportStatus($this->context(), $artifact);
    }

    /**
     * Download one completed verified export within the MCP inline-size ceiling.
     *
     * @param   string  $artifact  Completed export artifact UUID.
     *
     * @return  array<string, mixed>  Base64 artifact bytes and checksum metadata.
     *
     * @since   2.0.0
     */
    public function downloadBusinessReportExport(string $artifact): array
    {
        $this->require('business.record.export');

        return $this->businessReports->downloadExport($this->context(), $artifact);
    }

    /**
     * Resolve, capability-check, resource-authorize, and bind one generated-business mutation context.
     *
     * The generic delegate still enforces definition exposure and row policy, while this outer handler
     * records the coarse collection decision before either idempotency ledger is entered.
     *
     * @param   string  $operationId  Caller-chosen stable operation identity.
     * @param   string  $operation    Closed generated-business mutation name.
     *
     * @return  ExecutionContext  MCP child context carrying the same operation identity.
     *
     * @throws  InsufficientCapability  When the credential lacks the operation's capability.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses the collection write.
     * @throws  InvalidArgumentException  When the operation or operation identity is invalid.
     *
     * @since   2.0.0
     */
    private function businessMutationContext(string $operationId, string $operation): ExecutionContext
    {
        $capability = BusinessMcpHandlers::capabilityFor($operation);
        $this->require($capability);
        $this->preauthorize(
            $operationId,
            $capability,
            AuthorizationResource::collection('business_record'),
        );

        return $this->context($operationId);
    }

    /**
     * Fail unless the bound credential resolves to a principal holding at least one of several capabilities.
     *
     * Reserved for query tools whose canonical service deliberately admits several independent authorities and
     * then filters every row itself, such as the approval inbox.
     *
     * @param   list<string>  $capabilities  Capability codes any one of which admits the call.
     *
     * @return  AuthenticatedPrincipal  The resolved actor.
     *
     * @throws  InsufficientCapability  When no principal is bound, or it holds none of the capabilities.
     *
     * @since   2.0.0
     */
    private function requireAny(array $capabilities): AuthenticatedPrincipal
    {
        $principal = $this->principal();
        foreach ($capabilities as $capability) {
            if ($principal->hasCapability(Capability::fromString($capability))) {
                return $principal;
            }
        }

        throw new InsufficientCapability(implode('|', $capabilities));
    }

    /**
     * Fail unless the bound credential resolves to a principal holding a capability.
     *
     * The first line of every protected tool, and deliberately earlier and cheaper than the gateway: it asks
     * whether the caller holds the capability at all, before any resource has been named or any work started.
     * It is a floor, not a substitute for `preauthorize()` — holding a capability is not permission to
     * exercise it on a particular record.
     *
     * @param   string  $capability  Capability code the tool needs, such as `content.read`.
     *
     * @return  AuthenticatedPrincipal  The resolved actor, once it is known to hold the capability.
     *
     * @throws  InsufficientCapability  When no principal is bound, or the principal lacks the capability.
     * @throws  InvalidArgumentException  When the code is not a valid capability identifier.
     *
     * @since   2.0.0
     */
    private function require(string $capability): AuthenticatedPrincipal
    {
        $principal = $this->principal();
        $value = Capability::fromString($capability);
        if (!$principal->hasCapability($value)) {
            throw new InsufficientCapability($capability);
        }

        return $principal;
    }

    /**
     * Resolve the human actor the bound identity currently authenticates.
     *
     * @return  AuthenticatedPrincipal  The actor these handlers are acting as.
     *
     * @throws  InsufficientCapability  When no context is bound, or the bound context carries no human
     *          principal — a system context authorizes nothing on this surface.
     *
     * @since   2.0.0
     */
    private function principal(): AuthenticatedPrincipal
    {
        return AuthenticatedPrincipal::of($this->context())
            ?? throw new InsufficientCapability('authenticated');
    }

    /**
     * Resolve the execution context this call runs under, re-proving a retained credential first.
     *
     * The resident extension generation is proven before even the retained credential is refreshed. That
     * makes the common boundary cover both HTTP and long-lived stdio MCP transports: once replacement,
     * withdrawal or trust revocation advances authority, no subsequent protected tool or resource can enter
     * an application service through a handler object built from the old graph.
     *
     * A retained credential takes precedence over a bound context and is re-read on every call rather than
     * cached, which is what makes a revoked stdio token stop the very next tool call. Passing an operation
     * identifier returns a child context carrying it as the request identifier, so the authorization decision,
     * the idempotency claim and the audit record of one tool call all tie together.
     *
     * @param   ?string  $operationId  Operation identifier to derive a child context from, or null for the
     *          bound context itself.
     *
     * @return  ExecutionContext  The context to authorize and audit this call under.
     *
     * @throws  InsufficientCapability  When nothing is bound, or the retained credential no longer verifies.
     * @throws  InvalidArgumentException  When the operation identifier cannot serve as a request identifier.
     * @throws  \RuntimeException  When this handler graph belongs to a superseded extension generation.
     *
     * @since   2.0.0
     */
    private function context(?string $operationId = null): ExecutionContext
    {
        $this->extensionRuntime?->assertCurrent();
        $context = $this->contextRefresh !== null
            ? ($this->contextRefresh)()
            : $this->executionContext;
        if (!$context instanceof ExecutionContext) {
            throw new InsufficientCapability('authenticated');
        }

        return $operationId === null
            ? $context
            : $context->child('mcp-' . $operationId, $operationId);
    }

    /**
     * Run one published-version status change behind the definitions capability and the mutation fence.
     *
     * @param   string  $operationId  Idempotency key this write is fenced on.
     * @param   string  $action       `supersede`, `deprecate` or `reject`.
     * @param   string  $handle       Definition handle or UUID.
     * @param   int     $version      Published version.
     *
     * @return  array<string, mixed>  The version record, or the stored copy on a repeat.
     *
     * @throws  InsufficientCapability  When the caller lacks `content.update`.
     *
     * @since   2.0.0
     */
    private function retireBusinessDefinition(
        string $operationId,
        string $action,
        string $handle,
        int $version,
    ): array {
        $this->require('content.update');
        $this->preauthorize(
            $operationId,
            'content.update',
            AuthorizationResource::collection('business_definition'),
        );

        return $this->mutations->run(
            $this->context($operationId),
            'business_definition.' . $action,
            $operationId,
            compact('handle', 'version'),
            fn (): array => $this->definitionVersion(match ($action) {
                'supersede' => $this->definitions->supersede($this->context($operationId), $handle, $version),
                'deprecate' => $this->definitions->deprecate($this->context($operationId), $handle, $version),
                default => $this->definitions->reject($this->context($operationId), $handle, $version),
            }),
        );
    }

    /**
     * Project one definition draft as the draft tools answer it.
     *
     * @param   DefinitionDraft  $draft  Stored draft.
     *
     * @return  array<string, mixed>  Revision, checksum, author, time and definition body.
     *
     * @since   2.0.0
     */
    private static function definitionDraft(DefinitionDraft $draft): array
    {
        return [
            'revision' => $draft->revision,
            'checksum' => $draft->checksum,
            'updated_by' => $draft->updatedBy,
            'updated_at' => $draft->updatedAt->format(DATE_ATOM),
            'definition' => $draft->definition->toArray(),
        ];
    }

    /**
     * Run one composition read or provisioning and project it as the REST document.
     *
     * A missing, unreadable or unprojectable model answers exactly as a composition never provisioned, as REST
     * and the Studio host do, and a Blueprint locked to another published theme is the screen's refusal.
     *
     * @param   string                                                                $contentType  Type UUID.
     * @param   int                                                                   $version      Type version.
     * @param   callable(StudioContentCompositionService): ?StudioContentComposition  $operation    Service call.
     *
     * @return  array<string, mixed>  Coordinates, model, binding and the exact Blueprint head.
     *
     * @throws  ContentModelNotFound  When there is no composition to answer, answered as `resource.not_found`.
     * @throws  \DomainException  When the Blueprint is locked to another published theme.
     *
     * @since   2.0.0
     */
    private function studioComposition(string $contentType, int $version, callable $operation): array
    {
        try {
            $composition = $operation($this->studioCompositions());
        } catch (StudioCompositionThemeMismatch $mismatch) {
            throw new \DomainException('The Blueprint is locked to a different published theme.', 0, $mismatch);
        } catch (StudioProjectionRejected) {
            $composition = null;
        }

        return ($composition ?? throw new ContentModelNotFound('composition', $contentType, $version))->toArray();
    }

    /**
     * Resolve the Blueprint composition service this server was composed with.
     *
     * @return  StudioContentCompositionService  Composition service.
     *
     * @throws  InvalidArgumentException  When the server was composed without compositions.
     *
     * @since   2.0.0
     */
    private function studioCompositions(): StudioContentCompositionService
    {
        return $this->compositions
            ?? throw new InvalidArgumentException('Studio compositions are unavailable on this server.');
    }

    /**
     * Resolve the content model service this server was composed with.
     *
     * @return  ContentModelService  Content model service.
     *
     * @throws  InvalidArgumentException  When the server was composed without content models.
     *
     * @since   2.0.0
     */
    private function contentModels(): ContentModelService
    {
        return $this->models
            ?? throw new InvalidArgumentException('Content models are unavailable on this server.');
    }

    /**
     * Resolve the wording overrides service this server was composed with.
     *
     * @return  MessageOverrideService  Wording overrides service.
     *
     * @throws  InvalidArgumentException  When the server was composed without wording overrides.
     *
     * @since   2.0.0
     */
    private function wordingOverrides(): MessageOverrideService
    {
        return $this->wording
            ?? throw new InvalidArgumentException('Wording overrides are unavailable on this server.');
    }

    /**
     * Resolve one administered wording layer; the service refuses any other layer again.
     *
     * @param   string  $layer  `site` or `organization`.
     *
     * @return  MessageCatalogueLayer  The named layer.
     *
     * @throws  InvalidArgumentException  When the value names no administered layer.
     *
     * @since   2.0.0
     */
    private static function wordingLayer(string $layer): MessageCatalogueLayer
    {
        $resolved = MessageCatalogueLayer::tryFrom($layer);
        if ($resolved !== MessageCatalogueLayer::Site && $resolved !== MessageCatalogueLayer::Organization) {
            throw new InvalidArgumentException('An administered wording layer is required.');
        }

        return $resolved;
    }

    /**
     * Resolve the media library this server was composed with.
     *
     * @return  MediaService  Media library service.
     *
     * @throws  InvalidArgumentException  When the server was composed without the media library.
     *
     * @since   2.0.0
     */
    private function mediaLibrary(): MediaService
    {
        return $this->media
            ?? throw new InvalidArgumentException('The media library is unavailable on this server.');
    }

    /**
     * Require the gateway's approval for a write before the mutation fence is entered.
     *
     * Where `require()` only proves the caller holds a capability, this asks whether it may exercise that
     * capability on this particular resource, and the decision is recorded before it is acted on. It runs
     * ahead of `McpMutationGuard`, so a refused call claims no idempotency key and leaves nothing to clean up.
     *
     * @param   string                 $operationId  Operation identifier the child context is derived from, so
     *          the decision is recorded against the same request as the write.
     * @param   string                 $action       Capability code being exercised.
     * @param   AuthorizationResource  $resource     Collection or item the action is aimed at.
     *
     * @return  void
     *
     * @throws  InsufficientCapability  When no principal is bound to these handlers.
     * @throws  \Kumwe\Access\AuthorizationDenied  When policy refuses this actor the action on
     *          this resource.
     * @throws  InvalidArgumentException  When the code is not a valid capability identifier, or the operation
     *          identifier cannot serve as a request identifier.
     *
     * @since   2.0.0
     */
    private function preauthorize(string $operationId, string $action, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed(
            $this->context($operationId),
            Capability::fromString($action),
            $resource,
        );
    }

    /**
     * Run a trust-key write under the extension lifecycle lock and the idempotency fence.
     *
     * The advisory lifecycle lock intentionally surrounds the complete mutation guard. This keeps the lock held
     * through the guard's outer transaction commit or rollback while nested TrustStore calls re-enter it safely.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Actor the write is authorized and audited as.
     * @param   string                $operation    Operation name recorded for the write, under an `mcp.` prefix.
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   array<string, mixed>  $input        Arguments recorded with the claim and hashed, so reusing the
     *          identifier with different arguments is refused rather than replayed.
     * @param   callable(): TResult   $mutation     The trust-store write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  Whatever the write returned on its first run, or the stored copy on a repeat.
     *
     * @since   2.0.0
     */
    private function runTrustMutation(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->trust->synchronizedLifecycle(
            fn (): array => $this->mutations->run($context, $operation, $operationId, $input, $mutation),
        );
    }

    /**
     * Run an extension lifecycle write under the same lock and fence as a trust mutation.
     *
     * Extension lifecycle changes and trust-key changes share one installation-wide lock, so at most one of
     * them is in flight across the installation and an activation cannot run while the key set it is verified
     * against is moving. As in `runTrustMutation()`, the lock encloses the whole guard, so it is still held
     * when the guard's transaction commits or rolls back.
     *
     * @template TResult of array<string, mixed>
     *
     * @param   ExecutionContext      $context      Actor the write is authorized and audited as.
     * @param   string                $operation    Operation name recorded for the write, under an `mcp.` prefix.
     * @param   string                $operationId  Idempotency key this write is fenced on.
     * @param   array<string, mixed>  $input        Arguments recorded with the claim and hashed, so reusing the
     *          identifier with different arguments is refused rather than replayed.
     * @param   callable(): TResult   $mutation     The lifecycle write to perform, invoked at most once per
     *          identifier and from inside the fenced transaction.
     *
     * @return  TResult  Whatever the write returned on its first run, or the stored copy on a repeat.
     *
     * @since   2.0.0
     */
    private function runExtensionMutation(
        ExecutionContext $context,
        string $operation,
        string $operationId,
        array $input,
        callable $mutation,
    ): array {
        return $this->trust->synchronizedLifecycle(
            fn (): array => $this->mutations->run($context, $operation, $operationId, $input, $mutation),
        );
    }
}
