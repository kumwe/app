<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use JsonException;
use Kumwe\CMS\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\CMS\Extension\Contribution\AdministratorViewRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Presentation\Asset\ViteAssetManifest;
use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\CMS\Presentation\Twig\IsolatedTwigEnvironmentFactory;
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
     * Wire the renderer to its themed environment, its fallback, and the sources of the shell data.
     *
     * @param  AdministratorTwigEnvironment      $twig            Isolated environment resolving core
     *         administrator templates, the activated theme layout, and extension views.
     * @param  RecoveryAdministratorRenderer     $recovery        Theme-free renderer a failed render falls
     *         back to.
     * @param  ?AdministratorNavigationRegistry  $navigation      Registry the menu is built from; null uses
     *         the core-only registry.
     * @param  ?ViteAssetManifest                $assets          Manifest of built frontend files; null
     *         falls back to the unhashed administrator stylesheet and module.
     * @param  ?AdministratorViewRegistry        $extensionViews  Resolves an extension's view name to the
     *         template it registered; null leaves `renderExtension()` unusable.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AdministratorTwigEnvironment $twig,
        private RecoveryAdministratorRenderer $recovery,
        private ?AdministratorNavigationRegistry $navigation = null,
        private ?ViteAssetManifest $assets = null,
        private ?AdministratorViewRegistry $extensionViews = null,
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
                $this->sharedData($data, AdministratorNavigationRegistry::core()),
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
            'business-history', 'business-confirm', 'business-bulk-confirm',
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
                $this->sharedData($data, AdministratorNavigationRegistry::core()),
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
        $registry = $navigationRegistry ?? $this->navigation ?? AdministratorNavigationRegistry::core();
        $navigation = $registry->visible($capabilities);
        $assetEntry = ($this->assets ?? new ViteAssetManifest(''))->entry(
            'assets/administrator/main.ts',
            '/assets/administrator.css',
            '/assets/administrator.js',
        );
        $data['administrator_navigation'] = $navigation;
        $data['administrator_workspaces'] = $registry->visibleWorkspaces($capabilities, $navigation);
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
}
