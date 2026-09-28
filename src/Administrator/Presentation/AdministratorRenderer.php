<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Presentation;

use JsonException;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\App\Presentation\Asset\ViteAssetManifest;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteRenderer;
use Twig\Error\Error;

/**
 * Renders an administrator screen through the themed Twig environment, with the shell data every page needs.
 *
 * Handlers pass a template name and their own view variables and nothing else; this fills in the parts
 * the shell cannot be drawn without — the menu the actor is entitled to see, its workspace headings, the
 * built stylesheet and module lists, and the JSON the command palette reads — so no handler assembles
 * them and none can substitute a menu of its own. Its second job is to keep an operator from being
 * locked out of the back office: a Twig error raised while rendering an activated theme or an extension
 * view is caught and the page is drawn again by `RecoveryAdministratorRenderer` from the protected core
 * templates against a core-only menu, so a broken contribution degrades one page rather than the whole
 * administrator.
 *
 * @since  2.0.0
 */
final readonly class AdministratorRenderer
{
    /**
     * Catalogue identifiers of the wording core's own navigation entries render in, keyed by entry.
     *
     * A contributed navigation definition carries its label and description as source-language text,
     * because the contribution contract is locale-free. Core's entries are the one set whose wording
     * this repository also authors in all nine catalogues, so they resolve here, per request, through
     * the same translator the templates use. An extension's entries keep the text their contributor
     * declared. Each identifier is written out rather than derived so the catalogue gate sees it
     * referenced and a renamed entry cannot silently fall back to English.
     *
     * @var    array<string, array{label: string, description: string}>
     * @since  2.0.0
     */
    private const array CORE_NAVIGATION_WORDING = [
        'core.dashboard' => [
            'label' => 'core.navigation.administrator.dashboard.label',
            'description' => 'core.navigation.administrator.dashboard.description',
        ],
        'core.content' => [
            'label' => 'core.navigation.administrator.content.label',
            'description' => 'core.navigation.administrator.content.description',
        ],
        'core.create-content' => [
            'label' => 'core.navigation.administrator.create-content.label',
            'description' => 'core.navigation.administrator.create-content.description',
        ],
        'core.media' => [
            'label' => 'core.navigation.administrator.media.label',
            'description' => 'core.navigation.administrator.media.description',
        ],
        'core.models' => [
            'label' => 'core.navigation.administrator.models.label',
            'description' => 'core.navigation.administrator.models.description',
        ],
        'core.navigation' => [
            'label' => 'core.navigation.administrator.navigation.label',
            'description' => 'core.navigation.administrator.navigation.description',
        ],
        'core.wording' => [
            'label' => 'core.navigation.administrator.wording.label',
            'description' => 'core.navigation.administrator.wording.description',
        ],
        'core.business-definitions' => [
            'label' => 'core.navigation.administrator.business-definitions.label',
            'description' => 'core.navigation.administrator.business-definitions.description',
        ],
        'core.business-records' => [
            'label' => 'core.navigation.administrator.business-records.label',
            'description' => 'core.navigation.administrator.business-records.description',
        ],
        'core.business-reports' => [
            'label' => 'core.navigation.administrator.business-reports.label',
            'description' => 'core.navigation.administrator.business-reports.description',
        ],
        'core.business-schema-plans' => [
            'label' => 'core.navigation.administrator.business-schema-plans.label',
            'description' => 'core.navigation.administrator.business-schema-plans.description',
        ],
        'core.access' => [
            'label' => 'core.navigation.administrator.access.label',
            'description' => 'core.navigation.administrator.access.description',
        ],
        'core.business-security' => [
            'label' => 'core.navigation.administrator.business-security.label',
            'description' => 'core.navigation.administrator.business-security.description',
        ],
        'core.extensions' => [
            'label' => 'core.navigation.administrator.extensions.label',
            'description' => 'core.navigation.administrator.extensions.description',
        ],
        'core.automation' => [
            'label' => 'core.navigation.administrator.automation.label',
            'description' => 'core.navigation.administrator.automation.description',
        ],
        'core.settings' => [
            'label' => 'core.navigation.administrator.settings.label',
            'description' => 'core.navigation.administrator.settings.description',
        ],
    ];

    /**
     * Catalogue identifiers of core's sidebar group headings and their descriptions, keyed by group.
     *
     * @var    array<string, array{label: string, description: string}>
     * @since  2.0.0
     */
    private const array CORE_WORKSPACE_WORDING = [
        'core.workspace' => [
            'label' => 'core.navigation.administrator_workspace.workspace.label',
            'description' => 'core.navigation.administrator_workspace.workspace.description',
        ],
        'core.structure' => [
            'label' => 'core.navigation.administrator_workspace.structure.label',
            'description' => 'core.navigation.administrator_workspace.structure.description',
        ],
        'core.system' => [
            'label' => 'core.navigation.administrator_workspace.system.label',
            'description' => 'core.navigation.administrator_workspace.system.description',
        ],
    ];

    /**
     * Wire the renderer to its themed environment, its fallback, and the sources of the shell data.
     *
     * @param  AdministratorTwigEnvironment      $twig                        Isolated environment resolving core
     *         administrator templates, the activated theme layout, and extension views.
     * @param  RecoveryAdministratorRenderer     $recovery                    Theme-free renderer a failed render falls
     *         back to.
     * @param  CanonicalEncoder                  $canonicalEncoder            Host encoder the core-only recovery
     *         menu registers its contribution set with.
     * @param  ?AdministratorNavigationRegistry  $navigation                  Registry the menu is built from; null uses
     *         the core-only registry.
     * @param  ?ViteAssetManifest                $assets                      Manifest of built frontend files; null
     *         falls back to the unhashed administrator stylesheet and module.
     * @param  ?AdministratorViewRegistry        $extensionViews              Resolves an extension's view name to the
     *         template it registered; null leaves `renderExtension()` unusable.
     * @param  ?object                           $extensionRequestProvenance  Private composition-root authority
     *         required to mint extension route renderer capabilities.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorTwigEnvironment $twig,
        private RecoveryAdministratorRenderer $recovery,
        private CanonicalEncoder $canonicalEncoder,
        private ?AdministratorNavigationRegistry $navigation = null,
        private ?ViteAssetManifest $assets = null,
        private ?AdministratorViewRegistry $extensionViews = null,
        private ?object $extensionRequestProvenance = null,
    ) {
    }

    /**
     * Render a core administrator template into the HTML body of a response.
     *
     * The `active_navigation` key is derived from the template name when the caller left it unset, so
     * the shell highlights the current screen without every handler repeating that mapping. A Twig error
     * from the activated theme is caught and the same screen is drawn again from the protected core
     * templates against a core-only menu, which is what keeps a broken theme recoverable from inside the
     * administrator.
     *
     * @param   string                $template  Template name without the `.twig` suffix, such as `dashboard`.
     * @param   array<string, mixed>  $data      View variables for the template, by Twig variable name.
     *
     * @return  string  The rendered HTML document, from the theme or from the recovery templates.
     *
     * @throws  \RuntimeException  When the asset manifest exists but cannot be read, is not valid JSON, or
     *          declares no usable files for the administrator entry point, or the navigation cannot be
     *          JSON encoded.
     *
     * @since   2.0.0
     */
    public function render(string $template, array $data = []): string
    {
        $data['active_navigation'] = $data['active_navigation'] ?? $this->activeNavigation($template);
        $data = $this->sharedData($data);
        try {
            return $this->twig->render($template . '.twig', $data);
        } catch (Error) {
            return $this->recovery->render(
                $template,
                $this->sharedData($data, AdministratorNavigationRegistry::core($this->canonicalEncoder)),
            );
        }
    }

    /**
     * Render one view an extension contributed, from inside that extension's own Twig namespace.
     *
     * The view name is resolved through the view registry rather than used as a path, and the template
     * it resolves to is prefixed with the extension's own namespace, so a contribution can only ever
     * render templates it registered. The view name doubles as the menu entry to highlight unless the
     * caller named one. A Twig error falls back to the core `extension-error` screen rather than to the
     * view that failed.
     *
     * @param   string                $extension  Extension identifier in `vendor/name` form.
     * @param   string                $view       Name of a view that extension registered.
     * @param   array<string, mixed>  $data       View variables for the template, by Twig variable name.
     *
     * @return  string  The rendered HTML document, or the core extension-error page when rendering failed.
     *
     * @throws  \LogicException  When the renderer was wired without an administrator view registry.
     * @throws  \InvalidArgumentException  When the identifier is not a `vendor/name` pair, or the view is
     *          unknown or belongs to another extension.
     * @throws  \RuntimeException  When the asset manifest exists but cannot be read, is not valid JSON, or
     *          declares no usable files for the administrator entry point, or the navigation cannot be
     *          JSON encoded.
     *
     * @since   2.0.0
     */
    public function renderExtension(string $extension, string $view, array $data = []): string
    {
        $owner = ContributionOwner::extension($extension);
        $template = $this->extensionViews?->template($owner, $view)
            ?? throw new \LogicException('The administrator extension view registry is unavailable.');
        $data['active_navigation'] ??= $view;
        return $this->renderTemplate(
            '@' . IsolatedTwigEnvironmentFactory::extensionNamespace($extension) . '/' . $template,
            $data,
        );
    }

    /**
     * Mint a renderer capability closed over one validated extension owner and signed view.
     *
     * @param   string  $extension  Owning `vendor/name` package identifier.
     * @param   string  $view       Signed view identifier the capability may render.
     *
     * @return  AdministratorRouteRenderer  Renderer bound to exactly this owner, view, and provenance.
     *
     * @since   2.0.0
     */
    public function forExtensionRoute(string $extension, string $view): AdministratorRouteRenderer
    {
        $provenance = $this->extensionRequestProvenance
            ?? throw new \LogicException('Extension route rendering requires the private host provenance.');

        return new AdministratorContributionRenderer($this, $extension, $view, $provenance);
    }

    /**
     * Project the same capability- and live-trust-filtered navigation used by the administrator shell.
     *
     * Dashboard composition consumes this method so workflow widgets and shortcut choices cannot drift
     * from the command palette or sidebar, and no handler needs direct mutable access to the contribution
     * registry. The returned hrefs have already passed the registry's owner confinement rules.
     *
     * @param   array<string, true>  $capabilities  Current actor capability lookup.
     *
     * @return  list<array<string, int|string>>  Navigation rows safe to present to this actor.
     *
     * @since   2.0.0
     */
    public function visibleNavigation(array $capabilities): array
    {
        $registry = $this->navigation ?? AdministratorNavigationRegistry::core($this->canonicalEncoder);

        return $this->localizedNavigation($registry->visible($capabilities));
    }

    /**
     * Map a core template name to the menu entry the shell should mark as the current screen.
     *
     * Both content screens resolve to the same `core.content` entry, so opening the editor keeps the
     * content section highlighted. A template with no entry of its own yields an empty string, which
     * highlights nothing rather than defaulting to the dashboard.
     *
     * @param   string  $template  Core template name without the `.twig` suffix.
     *
     * @return  string  Identifier of the menu entry to highlight, empty when the screen has none.
     *
     * @since   2.0.0
     */
    private function activeNavigation(string $template): string
    {
        return match ($template) {
            'dashboard' => 'core.dashboard',
            'content-list', 'content-form' => 'core.content',
            'content-models' => 'core.models',
            'business-definitions' => 'core.business-definitions',
            'business-schema-plans' => 'core.business-schema-plans',
            'business-index', 'business-list', 'business-detail', 'business-document', 'business-form',
            'business-history', 'business-confirm', 'business-bulk-confirm', 'business-unavailable',
            'business-status' => 'core.business-records',
            'business-report' => 'core.business-reports',
            'navigation' => 'core.navigation',
            'access-control' => 'core.access',
            'business-security' => 'core.business-security',
            'extensions' => 'core.extensions',
            'automation' => 'core.automation',
            'settings' => 'core.settings',
            'media' => 'core.media',
            default => '',
        };
    }

    /**
     * Render an already-resolved template reference, falling back to the core extension-error screen.
     *
     * Kept separate from `render()` because the reference it takes is a complete namespaced Twig name
     * rather than a core template name, so it must not have `.twig` appended or be run through the
     * `active_navigation` mapping. The fallback renders the core `extension-error` screen instead of the
     * reference that failed, because the recovery environment carries no extension namespace to resolve
     * that reference in.
     *
     * @param   string                $template  Namespaced Twig reference including its suffix, as
     *          `renderExtension()` assembles it.
     * @param   array<string, mixed>  $data      View variables for the template, by Twig variable name.
     *
     * @return  string  The rendered HTML document, or the core extension-error page when rendering failed.
     *
     * @throws  \RuntimeException  When the asset manifest exists but cannot be read, is not valid JSON, or
     *          declares no usable files for the administrator entry point, or the navigation cannot be
     *          JSON encoded.
     *
     * @since   2.0.0
     */
    private function renderTemplate(string $template, array $data): string
    {
        $data = $this->sharedData($data);
        try {
            return $this->twig->render($template, $data);
        } catch (Error) {
            return $this->recovery->render(
                'extension-error',
                $this->sharedData($data, AdministratorNavigationRegistry::core($this->canonicalEncoder)),
            );
        }
    }

    /**
     * Add the shell data every administrator page is drawn with, whatever the caller supplied.
     *
     * The navigation, workspace and asset keys are always overwritten rather than merged, so a handler
     * cannot hand the layout a menu the actor is not entitled to. Capabilities are read back out of the
     * caller's own data and anything that is not a keyed map is discarded, which is what makes a render
     * with no capability map produce an empty menu instead of a complete one. The command palette
     * receives the same entries again as JSON, with angle brackets, quotes and ampersands hex escaped so
     * the payload cannot break out of the `<script>` block the layout emits it inside.
     *
     * @param   array<string, mixed>              $data                View variables to augment.
     * @param   ?AdministratorNavigationRegistry  $navigationRegistry  Registry the menu is built from;
     *          null uses the wired registry, or the core-only one when none was wired.
     *
     * @return  array<string, mixed>  The caller's data plus `administrator_navigation`,
     *          `administrator_workspaces`, `administrator_assets` and `administrator_commands_json`.
     *
     * @throws  \RuntimeException  When the asset manifest exists but cannot be read, is not valid JSON, or
     *          declares no usable files for the administrator entry point, or the navigation cannot be
     *          JSON encoded.
     *
     * @since   2.0.0
     */
    private function sharedData(
        array $data,
        ?AdministratorNavigationRegistry $navigationRegistry = null,
    ): array {
        $capabilities = $data['capabilities'] ?? [];
        if (!is_array($capabilities) || array_is_list($capabilities)) {
            $capabilities = [];
        }
        /** @var array<string, true> $capabilities */
        $registry = $navigationRegistry
            ?? $this->navigation
            ?? AdministratorNavigationRegistry::core($this->canonicalEncoder);
        $navigation = $navigationRegistry === null
            ? $this->visibleNavigation($capabilities)
            : $this->localizedNavigation($registry->visible($capabilities));
        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/administrator/main.ts',
            '/assets/administrator.css',
            '/assets/administrator.js',
        );
        $data['administrator_navigation'] = $navigation;
        $data['administrator_workspaces'] = $this->localizedWorkspaces(
            $registry->visibleWorkspaces($capabilities, $navigation),
        );
        $data['administrator_assets'] = $assetEntry->toArray();
        try {
            $data['administrator_commands_json'] = json_encode(
                $navigation,
                JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Administrator navigation cannot be encoded.', 0, $exception);
        }
        return $data;
    }

    /**
     * Put core's navigation entries into the language of the request, leaving contributed ones as declared.
     *
     * Only an entry owned by core and listed in `CORE_NAVIGATION_WORDING` is resolved, and its `group`
     * follows its workspace heading, so the sidebar, the quick links built from the same rows and the
     * command palette all speak one language. Without a registered translation extension, as in an
     * isolated rendering environment, the declared source text is kept rather than an identifier.
     *
     * @param   list<array<string, int|string>>  $rows  Visible navigation rows from the registry.
     *
     * @return  list<array<string, int|string>>  The same rows, in the same order, with core wording resolved.
     *
     * @since   2.0.0
     */
    private function localizedNavigation(array $rows): array
    {
        $translation = $this->translation();
        if ($translation === null) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            if (($row['owner'] ?? null) !== ContributionOwner::CORE) {
                continue;
            }
            $wording = self::CORE_NAVIGATION_WORDING[(string) ($row['id'] ?? '')] ?? null;
            if ($wording !== null) {
                $row['label'] = $this->resolve($translation, $wording['label'], (string) $row['label']);
                $row['description'] = $this->resolve(
                    $translation,
                    $wording['description'],
                    (string) ($row['description'] ?? ''),
                );
            }
            $group = self::CORE_WORKSPACE_WORDING[(string) ($row['workspace'] ?? '')] ?? null;
            if ($group !== null) {
                $row['group'] = $this->resolve($translation, $group['label'], (string) ($row['group'] ?? ''));
            }
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Put core's sidebar group headings and descriptions into the language of the request.
     *
     * @param   list<array{id: string, label: string, description: string, priority: int, dom_id: string}>  $rows
     *          Visible workspace groups from the registry.
     *
     * @return  list<array{id: string, label: string, description: string, priority: int, dom_id: string}>
     *          The same groups with core wording resolved and contributed groups unchanged.
     *
     * @since   2.0.0
     */
    private function localizedWorkspaces(array $rows): array
    {
        $translation = $this->translation();
        if ($translation === null) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            $wording = self::CORE_WORKSPACE_WORDING[$row['id']] ?? null;
            if ($wording === null) {
                continue;
            }
            $row['label'] = $this->resolve($translation, $wording['label'], $row['label']);
            $row['description'] = $this->resolve($translation, $wording['description'], $row['description']);
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * Answer the translation surface the themed environment's templates resolve messages through.
     *
     * @return  ?TranslationTwigExtension  The registered extension, or null when the environment has none.
     *
     * @since   2.0.0
     */
    private function translation(): ?TranslationTwigExtension
    {
        return $this->twig->hasExtension(TranslationTwigExtension::class)
            ? $this->twig->getExtension(TranslationTwigExtension::class)
            : null;
    }

    /**
     * Resolve one catalogue message, keeping the declared text when no catalogue layer carries it.
     *
     * @param   TranslationTwigExtension  $translation  Translation surface bound to the request locale.
     * @param   string                    $identifier   Catalogue identifier of the wording.
     * @param   string                    $declared     Source-language text the contribution declared.
     *
     * @return  string  The localized wording, or the declared text when the identifier is unresolved.
     *
     * @since   2.0.0
     */
    private function resolve(TranslationTwigExtension $translation, string $identifier, string $declared): string
    {
        $resolved = $translation->translate($identifier);

        return $resolved === $identifier ? $declared : $resolved;
    }
}
