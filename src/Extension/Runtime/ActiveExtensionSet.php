<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionProvider;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;
use LogicException;
use Mezzio\Application;
use Throwable;

/**
 * The extensions running in this process, together with the runtime surfaces they contributed.
 *
 * `ExtensionRuntimeLoader` fills one of these as it walks the compiled runtime map, then drives the two
 * phases that cannot run until every provider has registered its services: `contribute()` and `boot()`.
 * Afterwards the container shares the set as the single answer to what is active right now — the HTTP
 * application asks it to declare extension routes, the Twig factory asks it for theme and extension view
 * directories, and its count and contribution inventory are how a caller checks what a boot really
 * loaded. A boot that loads no map, because none is present or it is not trusted, shares an empty set
 * instead, so the recovery surfaces still answer.
 *
 * @since  2.0.0
 */
final class ActiveExtensionSet
{
    /**
     * Every loaded extension with the collaborators its later phases need, in runtime map order.
     *
     * `strict` records that the extension declared manifest schema 2 or newer, which decides whether it
     * may contribute runtime surfaces at all.
     *
     * @var    list<array{
     *             identifier: string,
     *             provider: ExtensionServiceProvider,
     *             container: ExtensionContainer,
     *             declared: ManifestContributionSet,
     *             strict: bool,
     *             runtime_entry: array<string, mixed>|null
     *         }>
     * @since  2.0.0
     */
    private array $extensions = [];

    /**
     * Template directory of the theme activated for a surface, keyed by `ThemeSurface` value.
     *
     * Only the single global administrator assignment is recorded here; a site theme is activated per
     * site and lives in `$siteThemePaths`.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $themePaths = [];

    /**
     * Extension owner of each activated global theme surface.
     *
     * The path alone is insufficient for lifecycle withdrawal: two package versions may publish the
     * same directory shape. Loader composition records the canonical owner beside it so removal never
     * guesses from filesystem names.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $themeOwners = [];

    /**
     * Template directory of the theme activated for a site, keyed by site identifier.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $siteThemePaths = [];

    /**
     * Extension owner of each site-specific theme assignment loaded into this process.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $siteThemeOwners = [];

    /**
     * Verified release coordinates for each resident extension.
     *
     * The signed runtime publication is the authority for these values. Keeping them beside the
     * extension provider lets consumers such as Studio lock the exact public theme release instead of
     * inferring identity or revision from a mutable template path.
     *
     * @var    array<string, array{version: string, deployed_tree_sha256: string}>
     * @since  2.0.0
     */
    private array $releases = [];

    /**
     * Start an empty set bound to the registries and the trust boundary its later phases need.
     *
     * @param  ExtensionContributionRegistrySet  $contributions  Registries every contribution is recorded
     *         in and read back from, shared with core so contributors cannot collide.
     * @param  ?TrustStore                       $trust          Trust boundary the routes declared here
     *         consult per request; null yields a set that holds extensions but cannot register routes.
     * @param  ?ExtensionExecutionGate           $execution      Exact runtime-generation fence used before
     *         resident extension preview code executes; null keeps executable preview bindings inert.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly ExtensionContributionRegistrySet $contributions,
        private ?TrustStore $trust = null,
        private ?ExtensionExecutionGate $execution = null,
    ) {
    }

    /**
     * Record an extension whose provider has registered its services and is ready for the later phases.
     *
     * Nothing runs here. `contribute()`, `boot()` and `registerRoutes()` visit the recorded entries
     * afterwards, each in the order the extensions were added.
     *
     * @param   string                     $identifier           Canonical `vendor/name` of the extension.
     * @param   ExtensionServiceProvider   $provider             Provider instance whose `register()` has
     *          already run.
     * @param   ExtensionContainer         $container            Restricted container that provider
     *          registered into, handed back to it in the later phases.
     * @param   ManifestContributionSet    $declared             Contributions the manifest declares, which
     *          the registrar then holds the provider to.
     * @param   bool                       $strictContributions  True when the manifest is schema 2 or newer, which
     *          is what admits the extension to `contribute()`.
     * @param   ?string                    $version              Verified installed package version, when this set
     *          was built from a signed runtime publication.
     * @param   ?string                    $deployedTreeSha256   Verified deployed release-tree digest paired with
     *          `$version`.
     * @param   array<string, mixed>|null  $runtimeEntry         Exact signed compiled entry that loaded this code,
     *          or null for an isolated compatibility fixture that must not execute resident contributions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(
        string $identifier,
        ExtensionServiceProvider $provider,
        ExtensionContainer $container,
        ManifestContributionSet $declared,
        bool $strictContributions,
        ?string $version = null,
        ?string $deployedTreeSha256 = null,
        ?array $runtimeEntry = null,
    ): void {
        if (($version === null) !== ($deployedTreeSha256 === null)) {
            throw new LogicException('Extension release coordinates must be supplied together.');
        }
        if (
            $version !== null
            && (
                preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $deployedTreeSha256 ?? '') !== 1
            )
        ) {
            throw new LogicException('Extension release coordinates are invalid.');
        }
        $this->extensions[] = [
            'identifier' => $identifier,
            'provider' => $provider,
            'container' => $container,
            'declared' => $declared,
            'strict' => $strictContributions,
            'runtime_entry' => $runtimeEntry,
        ];
        if ($version !== null && $deployedTreeSha256 !== null) {
            $this->releases[$identifier] = [
                'version' => $version,
                'deployed_tree_sha256' => $deployedTreeSha256,
            ];
        }
    }

    /**
     * View directories contributed by extensions, keyed by surface value and then by extension identifier.
     *
     * Both surfaces are seeded here, so a lookup for a surface no extension contributed to returns an
     * empty map rather than reaching an undefined key.
     *
     * @var    array<string, array<string, string>>
     * @since  2.0.0
     */
    private array $extensionViewPaths = [
        'site' => [],
        'administrator' => [],
    ];

    /**
     * Portal template directories keyed by contributing extension identifier.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $portalTemplatePaths = [];

    /**
     * Compiled message-catalogue directories keyed by contributing extension identifier.
     *
     * Insertion order is runtime-map order, and the extension layer resolves the first directory that
     * carries an identifier, so which extension wins a shared identifier is decided by the compiled
     * map rather than by filesystem enumeration.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $catalogueDirectories = [];

    /**
     * Record the template directory of the theme activated for one surface.
     *
     * @param   ThemeSurface  $surface     Surface the theme was activated for.
     * @param   string        $path        Absolute path of that theme's template directory.
     * @param   ?string       $identifier  Owning extension, or null for an isolated test fixture.
     *
     * @return  void
     *
     * @throws  LogicException  When a second theme claims a surface that already has one, which would
     *          otherwise leave the surface rendering from whichever happened to load last.
     *
     * @since   2.0.0
     */
    public function setThemePath(ThemeSurface $surface, string $path, ?string $identifier = null): void
    {
        if (isset($this->themePaths[$surface->value])) {
            throw new LogicException(sprintf('More than one %s theme was loaded.', $surface->value));
        }

        $this->themePaths[$surface->value] = $path;
        if ($identifier !== null) {
            $this->themeOwners[$surface->value] = $identifier;
        }
    }

    /**
     * Record a directory of views an extension contributes to one surface.
     *
     * Every extension gets at most one directory per surface, and it is loaded under a Twig namespace
     * derived from the identifier, so an extension's views can never shadow a core template name.
     *
     * @param   ThemeSurface  $surface     Surface those views render on.
     * @param   string        $identifier  Contributing extension, which becomes its Twig namespace.
     * @param   string        $path        Absolute path of the directory holding the views.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function addExtensionViewPath(ThemeSurface $surface, string $identifier, string $path): void
    {
        $this->extensionViewPaths[$surface->value][$identifier] = $path;
    }

    /**
     * Record one extension's isolated portal template directory.
     *
     * @param   string  $identifier  Contributing extension used as the Twig namespace.
     * @param   string  $path        Absolute directory containing portal templates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function addPortalTemplatePath(string $identifier, string $path): void
    {
        $this->portalTemplatePaths[$identifier] = $path;
    }

    /**
     * Record the directory an extension compiled its message catalogues into.
     *
     * This is how an extension contributes wording through the ordinary package path rather than
     * shipping a parallel string table: the directory joins the extension layer of the override
     * chain, so its messages sit above core and below anything a site or an organization
     * administers. An extension gets one directory, and recording a second replaces the first,
     * because a single extension has a single catalogue tree.
     *
     * @param   string  $identifier  Contributing extension, whose namespace its identifiers sit under.
     * @param   string  $path        Absolute path of the directory holding the compiled catalogues.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function addCatalogueDirectory(string $identifier, string $path): void
    {
        $this->catalogueDirectories[$identifier] = $path;
    }

    /**
     * Record the template directory of the theme activated for one site.
     *
     * @param   string   $siteIdentifier  Site the theme is activated for.
     * @param   string   $path            Absolute path of that theme's template directory.
     * @param   ?string  $identifier      Owning extension, or null for an isolated test fixture.
     *
     * @return  void
     *
     * @throws  LogicException  When a second theme claims a site that already has one.
     *
     * @since   2.0.0
     */
    public function setSiteThemePath(string $siteIdentifier, string $path, ?string $identifier = null): void
    {
        if (isset($this->siteThemePaths[$siteIdentifier])) {
            throw new LogicException(sprintf('More than one theme was loaded for site %s.', $siteIdentifier));
        }
        $this->siteThemePaths[$siteIdentifier] = $path;
        if ($identifier !== null) {
            $this->siteThemeOwners[$siteIdentifier] = $identifier;
        }
    }

    /**
     * Withdraw one resident extension and every registry, presentation and localization path it owns.
     *
     * Provider objects are dropped with the extension entry; the registry-set sweep removes every
     * declarative and executable contribution; and the owner maps remove Twig theme, namespaced view,
     * portal-template and compiled-catalogue paths. Raw event-manager listeners cannot be detached safely, so
     * their generation-aware registration wrapper separately makes them inert after the same change.
     *
     * @param   string  $identifier  Canonical `vendor/name` owner being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function withdraw(string $identifier): void
    {
        $this->extensions = array_values(array_filter(
            $this->extensions,
            static fn (array $extension): bool => $extension['identifier'] !== $identifier,
        ));
        $this->contributions->remove(ContributionOwner::extension($identifier));
        foreach ($this->extensionViewPaths as &$paths) {
            unset($paths[$identifier]);
        }
        unset($paths);
        unset($this->portalTemplatePaths[$identifier], $this->catalogueDirectories[$identifier]);
        unset($this->releases[$identifier]);
        foreach ($this->themeOwners as $surface => $owner) {
            if ($owner === $identifier) {
                unset($this->themeOwners[$surface], $this->themePaths[$surface]);
            }
        }
        foreach ($this->siteThemeOwners as $site => $owner) {
            if ($owner === $identifier) {
                unset($this->siteThemeOwners[$site], $this->siteThemePaths[$site]);
            }
        }
    }

    /**
     * Withdraw every extension object loaded from a generation that is no longer authoritative.
     *
     * A single lifecycle mutation supersedes the complete signed graph, not just its named package.
     * Clearing all extension owners is therefore the safe in-process response: core registrations stay
     * available, while no cross-extension dependency can keep executing against a mixed generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function withdrawAll(): void
    {
        $identifiers = array_map(
            static fn (array $extension): string => $extension['identifier'],
            $this->extensions,
        );
        foreach ($identifiers as $identifier) {
            $this->withdraw($identifier);
        }
    }

    /**
     * Run the boot phase of every extension that takes part in the request runtime.
     *
     * Providers that only publish services are skipped; only a `RuntimeExtension` has a boot phase. This
     * is the last phase, after every provider registered and after `contribute()`, so an extension
     * booting here sees its own container fully registered and registries nobody will add to any more.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension['provider'] instanceof RuntimeExtension) {
                $extension['provider']->boot($extension['container']);
            }
        }
    }

    /**
     * Collect the contributions of every loaded extension into the shared registries.
     *
     * Runs after every provider registered services and before boot or route registration. Manifest
     * schema and provider contract have to agree here: an extension on a strict schema must implement
     * `ExtensionContributionProvider`, and one still on schema 1 may not contribute at all. Each provider
     * registers through a registrar scoped to its own owner and to its manifest declarations, so it can
     * neither claim an identifier outside its namespace nor register something it did not declare. The
     * assembled business definitions are validated once at the end, when every contributor has been seen,
     * because a definition may point at a field type or an entity another extension contributes.
     *
     * @return  void
     *
     * @throws  LogicException  When a strict extension does not implement the contribution provider
     *          contract, or a schema-1 extension does implement it and tries to contribute.
     * @throws  \InvalidArgumentException  When a provider omits or repeats a contribution its manifest
     *          declared, or claims an identifier it does not own.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the assembled
     *          business definition graph does not validate.
     *
     * @since   2.0.0
     */
    public function contribute(): void
    {
        foreach ($this->extensions as $extension) {
            $provider = $extension['provider'];
            if (!$provider instanceof ExtensionContributionProvider) {
                if ($extension['strict']) {
                    throw new LogicException(sprintf(
                        'Strict extension %s must implement the contribution provider contract.',
                        $extension['identifier'],
                    ));
                }
                continue;
            }
            if (!$extension['strict']) {
                throw new LogicException(sprintf(
                    'Extension %s must use manifest schema 2 or newer before contributing runtime surfaces.',
                    $extension['identifier'],
                ));
            }
            $registrar = $this->contributions->registrar(
                ContributionOwner::extension($extension['identifier']),
                $extension['declared'],
            );
            $provider->contribute($registrar, $extension['container']);
            $registrar->complete();
            $this->activateStudioPreviewRenderers($extension);
        }
        $this->contributions->validateBusinessDefinitions();
        $this->contributions->validateIntegrationContributions();
    }

    /**
     * Activate only explicit owner-local services behind exact canonical block coordinates.
     *
     * A manifest renderer value is never treated as a class or factory. It can only address a service
     * the already-verified provider shared under its restricted `extension.<owner>.` namespace. Missing,
     * malformed, foreign, stale-test, or incorrectly typed registrations stay inert so a Gate-A package
     * that shipped declarations before this execution seam continues to activate unchanged.
     *
     * @param   array{
     *              identifier: string,
     *              provider: ExtensionServiceProvider,
     *              container: ExtensionContainer,
     *              declared: ManifestContributionSet,
     *              strict: bool,
     *              runtime_entry: array<string, mixed>|null
     *          }  $extension  Loaded extension and exact runtime provenance.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function activateStudioPreviewRenderers(array $extension): void
    {
        $runtimeEntry = $extension['runtime_entry'];
        $runtimeVersion = is_array($runtimeEntry) ? $runtimeEntry['version'] ?? null : null;
        if ($this->trust === null || $this->execution === null || !is_string($runtimeVersion)) {
            return;
        }
        $owner = ContributionOwner::extension($extension['identifier']);
        foreach ($extension['declared']->compositionHostBindings() as $binding) {
            if ($binding->kind !== CanonicalCompositionKind::BlockDefinition || $binding->renderer === null) {
                continue;
            }
            $registered = $this->contributions->canonicalCompositionDocuments()->definition(
                $owner,
                $binding->identifier(),
            );
            if (!$registered instanceof CanonicalCompositionDocument) {
                continue;
            }
            try {
                $definition = new StudioPreviewRendererContribution(
                    $owner,
                    $runtimeVersion,
                    $registered,
                    $binding,
                );
                $implementation = $extension['container']->get('extension.' . $binding->renderer);
                if (!$implementation instanceof StudioPreviewBlockRenderer) {
                    continue;
                }
                $this->contributions->studioPreviewRenderers()->register(
                    $owner,
                    $definition,
                    new TrustEnforcingStudioPreviewBlockRenderer(
                        $implementation,
                        $this->trust,
                        $this->execution,
                        $extension['identifier'],
                        $runtimeEntry,
                    ),
                );
            } catch (Throwable) {
                // An unavailable or contradictory executable binding remains an inert unresolved block.
            }
        }
    }

    /**
     * Declare every extension route on the HTTP application being composed.
     *
     * Two kinds of route are mounted: those a `RuntimeExtension` declares itself, through a registrar
     * that confines them to its own path and route-name namespace, and the administrator routes
     * extensions contributed through the registries. Both are wrapped in a per-request trust check, so a
     * route keeps answering only while its extension stays enabled and trusted — the router is composed
     * once and never rebuilt when an extension is disabled.
     *
     * @param   Application            $application     Mezzio application the routes are declared on.
     * @param   AdministratorRenderer  $renderer        Renderer handed to each contributed administrator
     *          route handler when it is built.
     * @param   ?PortalRenderer        $portalRenderer  Renderer handed to contributed portal handlers, or null
     *          when composing the recovery-only application.
     *
     * @return  void
     *
     * @throws  LogicException  When the set was built without a trust store, since no route may be mounted
     *          without the boundary that makes it revocable.
     *
     * @since   2.0.0
     */
    public function registerRoutes(
        Application $application,
        AdministratorRenderer $renderer,
        ?PortalRenderer $portalRenderer = null,
    ): void {
        foreach ($this->extensions as $extension) {
            if ($extension['provider'] instanceof RuntimeExtension) {
                $trust = $this->trust
                    ?? throw new LogicException('Runtime extensions require an installed trust boundary.');
                // The immutable, signed runtime publication is the request-bootstrap
                // trust attestation. Route handlers are still wrapped in live trust
                // enforcement by the registrar, but ordinary application bootstrap
                // must not acquire the global lifecycle lock or hash the runtime tree.
                $extension['provider']->registerRoutes(new MezzioExtensionRouteRegistrar(
                    $application,
                    $extension['identifier'],
                    $trust,
                ));
            }
        }
        $trust = $this->trust
            ?? throw new LogicException('Administrator extension routes require an installed trust boundary.');
        $this->contributions->routes()->registerInto($application, $trust, $renderer);
        if ($portalRenderer instanceof PortalRenderer) {
            $this->contributions->portalRoutes()->registerInto($application, $trust, $portalRenderer);
        }
    }

    /**
     * How many extensions this set loaded.
     *
     * @return  int  Number of providers recorded; zero both when the map named none and when the boot
     *          fell back to an empty set because no trusted map was available.
     *
     * @since   2.0.0
     */
    public function count(): int
    {
        return count($this->extensions);
    }

    /**
     * Locate the theme activated for a surface.
     *
     * @param   ThemeSurface  $surface  Surface being rendered.
     *
     * @return  ?string  Absolute path of the theme's template directory, or null when no theme is active
     *          for that surface and the built-in templates are the only ones to render from.
     *
     * @since   2.0.0
     */
    public function themePath(ThemeSurface $surface): ?string
    {
        return $this->themePaths[$surface->value] ?? null;
    }

    /**
     * Locate the theme activated for one site's front end.
     *
     * @param   string  $siteIdentifier  Site whose theme assignment applies to the request being rendered.
     *
     * @return  ?string  Absolute path of that site's theme template directory, or null when the site has
     *          no theme assigned.
     *
     * @since   2.0.0
     */
    public function siteThemePath(string $siteIdentifier): ?string
    {
        return $this->siteThemePaths[$siteIdentifier] ?? null;
    }

    /**
     * Resolve the signed release coordinate of the public theme assigned to one site.
     *
     * A null result means the site renders the built-in public theme. An extension-owned assignment
     * without verified release metadata fails closed: callers must never fabricate a revision from a
     * path or from the contribution document itself.
     *
     * @param   string  $siteIdentifier  Site whose public theme assignment is required.
     *
     * @return  array{id: string, version: string, revision: string}|null  Exact extension release, or
     *          null for the built-in public theme.
     *
     * @throws  LogicException  When a theme owner was loaded without signed release coordinates.
     *
     * @since   2.0.0
     */
    public function siteThemeRelease(string $siteIdentifier): ?array
    {
        $owner = $this->siteThemeOwners[$siteIdentifier] ?? null;
        if ($owner === null) {
            return null;
        }
        $release = $this->releases[$owner] ?? null;
        if ($release === null) {
            throw new LogicException('The active site theme has no verified release coordinate.');
        }

        return [
            'id' => $owner,
            'version' => $release['version'],
            'revision' => $release['deployed_tree_sha256'],
        ];
    }

    /**
     * List the extension view directories that belong on one surface's loader chain.
     *
     * @param   ThemeSurface  $surface  Surface the loader chain is being built for.
     *
     * @return  array<string, string>  Absolute directory paths keyed by contributing extension identifier,
     *          which the Twig factory turns into one namespace per extension.
     *
     * @since   2.0.0
     */
    public function extensionViewPaths(ThemeSurface $surface): array
    {
        return $this->extensionViewPaths[$surface->value];
    }

    /**
     * List the extension template directories available to the portal renderer.
     *
     * @return  array<string, string>  Absolute paths keyed by extension Twig namespace.
     *
     * @since   2.0.0
     */
    public function portalTemplatePaths(): array
    {
        return $this->portalTemplatePaths;
    }

    /**
     * List the compiled catalogue directories that make up the extension layer of the override chain.
     *
     * @return  list<string>  Absolute directory paths in runtime-map order, which is the order the
     *          extension layer resolves them in; empty when no active extension ships wording.
     *
     * @since   2.0.0
     */
    public function catalogueDirectories(): array
    {
        return array_values($this->catalogueDirectories);
    }

    /**
     * Report everything one extension contributed, for operator-facing inventory and diagnostics.
     *
     * @param   string  $identifier  Canonical `vendor/name` of the extension to report on.
     *
     * @return  array<string, mixed>  Contributions grouped by surface — `capabilities` alongside nested
     *          `administrator` and `business` groups — empty per surface where it contributed nothing.
     *
     * @since   2.0.0
     */
    public function contributionInventory(string $identifier): array
    {
        return $this->contributions->inventory(ContributionOwner::extension($identifier));
    }
}
