<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\Extension\Spi\Runtime\BootableExtension;
use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use Kumwe\Extension\Spi\Application\ExtensionServiceProvider;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Extension\Domain\ThemeSurface;
use Kumwe\App\Portal\Presentation\PortalRenderer;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Binding\ExtensionBindingProvider;
use LogicException;
use Mezzio\Application;

/**
 * The extensions running in this process, together with the runtime surfaces they contributed.
 *
 * `ExtensionRuntimeLoader` fills one of these as it walks the compiled runtime map, then drives the two
 * phases that cannot run until every provider has registered its services: canonical activation,
 * executable binding, and `boot()`.
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
     * @var    list<array{
     *             identifier: string,
     *             provider: ExtensionServiceProvider,
     *             container: ExtensionContainer,
     *             declared: ManifestContributions,
     *             version: ?string,
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
     *         resident extension preview code executes; null makes an executable preview binding fail.
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
     * Nothing runs here. The manifest activation, executable binding and `boot()` phases visit the
     * recorded entries afterwards, each in the order the extensions were added.
     *
     * @param   string                     $identifier          Canonical `vendor/name` of the extension.
     * @param   ExtensionServiceProvider   $provider            Provider instance whose `register()` has
     *          already run.
     * @param   ExtensionContainer         $container           Restricted container that provider
     *          registered into, handed back to it in the later phases.
     * @param   ManifestContributions      $declared            Canonical SDK graph declared by the manifest.
     * @param   ?string                    $version             Verified installed package version, when this set
     *          was built from a signed runtime publication.
     * @param   ?string                    $deployedTreeSha256  Verified deployed release-tree digest paired with
     *          `$version`.
     * @param   array<string, mixed>|null  $runtimeEntry        Exact signed compiled entry that loaded this code,
     *          or null for an isolated declarative fixture that cannot bind resident preview behavior.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(
        string $identifier,
        ExtensionServiceProvider $provider,
        ExtensionContainer $container,
        ManifestContributions $declared,
        ?string $version = null,
        ?string $deployedTreeSha256 = null,
        ?array $runtimeEntry = null,
    ): void {
        if ($declared->owner->identifier() !== $identifier) {
            throw new LogicException('An active manifest contribution graph must belong to its provider.');
        }
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
            'version' => $version,
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
     * Providers that only publish services are skipped; only a `BootableExtension` has a boot phase. This
     * is the last phase, after every provider registered and after canonical activation and binding, so an
     * extension booting here sees its own container fully registered and closed contribution registries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(): void
    {
        foreach ($this->extensions as $extension) {
            if ($extension['provider'] instanceof BootableExtension) {
                $extension['provider']->boot($extension['container']);
            }
        }
    }

    /**
     * Activate signed declarations directly and bind only their exact executable identifiers.
     *
     * Provider code receives no declarative registrar. The SDK graph is installed first; a provider
     * implementing `ExtensionBindingProvider` may then attach behavior by identifier, and completion
     * refuses every missing, duplicate, foreign, or wrong-kind binding. Schema-one manifests carry the
     * SDK's explicit empty graph and therefore have no alternate route or event execution channel.
     *
     * @return  void
     *
     * @throws  LogicException  When signed executable requirements have no binding provider.
     * @throws  \InvalidArgumentException  When executable bindings do not exactly satisfy the manifest.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the assembled
     *          business definition graph does not validate.
     *
     * @since   2.0.0
     */
    public function activate(): void
    {
        foreach ($this->extensions as $extension) {
            $provider = $extension['provider'];
            $registrar = $this->contributions->activateManifest(
                $extension['declared'],
                $this->trust,
                $this->execution,
                $extension['version'],
                $extension['runtime_entry'],
            );
            $required = $extension['declared']->executableBindingRequirements()->toArray();
            if (!$provider instanceof ExtensionBindingProvider) {
                if ($required !== []) {
                    throw new LogicException(sprintf(
                        'Extension %s must bind every executable identifier declared by its manifest.',
                        $extension['identifier'],
                    ));
                }
                $registrar->complete();
                continue;
            }
            $provider->bind($registrar, $extension['container']);
            $registrar->complete();
        }
        $this->contributions->validateBusinessDefinitions();
        $this->contributions->validateIntegrationContributions();
    }

    /**
     * Declare every extension route on the HTTP application being composed.
     *
     * Routes come only from signed manifest declarations whose executable factories were bound by ID.
     * The route registries retain the owner, declaration and factory together and mount every handler
     * behind live trust and authorization checks.
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
