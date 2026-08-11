<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\SemanticVersion;
use Kumwe\CMS\Extension\Domain\TemplateKisCompatibility;
use Kumwe\CMS\Presentation\ThemeSurface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Compiles a candidate theme ahead of activation so a broken package can never reach a visitor.
 *
 * Activating a theme that does not compile would turn every page into a render error — including the
 * administrator console an operator would use to undo the change. This validator runs before the
 * registry write: it insists the surface's entry templates are ordinary files rather than symlinks that
 * could reach outside the package, compiles every Twig file the package ships against the same loader
 * chain the renderer will use, and renders administrator layouts against synthetic host data to prove
 * protected KIS 1.0 shell contracts. Site markup remains theme-owned inside a minimal public document,
 * asset, navigation, and keyboard-recovery boundary. Every failure is reported as an
 * `InvalidArgumentException`, which `DoctrineExtensionManager` lets abort activation.
 *
 * @since  2.0.0
 */
final readonly class ThemePackageValidator
{
    /**
     * KIS major/minor standard implemented by this host.
     *
     * @var    string
     * @since  2.0.0
     */
    private const KIS_STANDARD = 'kis-1.0';

    /**
     * Public KIS component contract implemented by the shared Twig component library.
     *
     * @var    string
     * @since  2.0.0
     */
    private const KIS_COMPONENT_VERSION = '1.0.0';

    /**
     * Public KIS token contract implemented by the shared presentation properties.
     *
     * @var    string
     * @since  2.0.0
     */
    private const KIS_TOKEN_VERSION = '1.0.0';

    /**
     * Unique text used to prove the candidate exposes the document-title block.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TITLE_SENTINEL = 'KUMWE_KIS_TITLE_SENTINEL';

    /**
     * Unique text used to prove the candidate places page content inside its main landmark.
     *
     * @var    string
     * @since  2.0.0
     */
    private const CONTENT_SENTINEL = 'KUMWE_KIS_CONTENT_SENTINEL';

    /**
     * Unique rich-text value used to prove a public page keeps the presented body inside its main landmark.
     *
     * @var    string
     * @since  2.0.0
     */
    private const BODY_SENTINEL = 'KUMWE_KIS_BODY_SENTINEL';

    /**
     * Unique workspace label used to prove the shell consumes capability-filtered navigation data.
     *
     * @var    string
     * @since  2.0.0
     */
    private const WORKSPACE_SENTINEL = 'KUMWE_KIS_WORKSPACE_SENTINEL';

    /**
     * Unique navigation label used to prove the active item remains visible and identifiable.
     *
     * @var    string
     * @since  2.0.0
     */
    private const NAVIGATION_SENTINEL = 'KUMWE_KIS_NAVIGATION_SENTINEL';

    /**
     * URL a conforming administrator shell must render from the host stylesheet outlet.
     *
     * @var    string
     * @since  2.0.0
     */
    private const STYLESHEET_SENTINEL = '/kumwe-kis-validator.css';

    /**
     * URL a conforming administrator shell must render from the host module outlet.
     *
     * @var    string
     * @since  2.0.0
     */
    private const MODULE_SENTINEL = '/kumwe-kis-validator.js';

    /**
     * Stable identifier of the synthetic navigation item rendered during conformance validation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const NAVIGATION_ID = 'kis.validator.navigation';

    /**
     * Stable destination of the synthetic navigation item rendered during conformance validation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const NAVIGATION_HREF = '/administrator/kis-validator';

    /**
     * Stable public destination rendered as the current site navigation item during validation.
     *
     * @var    string
     * @since  2.0.0
     */
    private const SITE_NAVIGATION_HREF = '/kis-validator';

    /**
     * Bind the validator to the core template tree candidate themes inherit from.
     *
     * @param  string  $coreTemplateRoot  Directory holding the per-surface built-in template trees.
     *
     * @since  2.0.0
     */
    public function __construct(private string $coreTemplateRoot)
    {
    }

    /**
     * Assert that a theme directory compiles for the surface it is about to be activated on.
     *
     * The candidate directory is registered both anonymously and under a surface namespace, with the
     * core tree behind it, so a package that overrides only some templates still resolves. Twig errors
     * are re-thrown as `InvalidArgumentException` carrying the underlying message, so the caller has one
     * failure type to handle and the operator still sees which template broke. Both site entries and the
     * administrator layout are additionally rendered against KIS 1.0 sentinels, since a theme that
     * compiles can still discard navigation, assets, responsive metadata, or the keyboard recovery path.
     *
     * @param   string                    $themePath      Directory holding this surface's templates inside the package.
     * @param   ThemeSurface              $surface        Surface the theme is being activated on.
     * @param   TemplateKisCompatibility  $compatibility  Versioned KIS contract declared in the signed manifest.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the directory, an entry template, or any Twig file is bad.
     *
     * @since   2.0.0
     */
    public function validate(
        string $themePath,
        ThemeSurface $surface,
        TemplateKisCompatibility $compatibility,
    ): void {
        $this->validateCompatibility($compatibility);

        $resolved = realpath($themePath);
        if (!is_string($resolved) || !is_dir($resolved) || is_link($themePath)) {
            throw new InvalidArgumentException('The selected theme surface directory is invalid.');
        }

        foreach ($this->requiredEntries($surface) as $entry) {
            $file = $resolved . '/' . $entry;
            if (!is_file($file) || is_link($file)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s theme requires a regular %s entry template.',
                    $surface->value,
                    $entry,
                ));
            }
        }

        $loader = new FilesystemLoader();
        $loader->addPath($resolved);
        $loader->addPath($resolved, $surface === ThemeSurface::Site ? 'site-theme' : 'admin-theme');
        $corePath = $this->coreTemplateRoot . '/' . $surface->value;
        $loader->addPath($corePath);
        $loader->addPath($corePath, $surface === ThemeSurface::Site ? 'core-site' : 'core-admin');
        $componentPath = $this->coreTemplateRoot . '/interface-standard';
        if (is_dir($componentPath)) {
            $loader->addPath($componentPath, 'kis');
        }
        $twig = new Environment($loader, ['autoescape' => 'html', 'cache' => false, 'strict_variables' => true]);
        $templates = $this->templates($resolved);

        if ($templates === []) {
            throw new InvalidArgumentException('The selected theme surface contains no Twig templates.');
        }

        try {
            foreach ($templates as $template) {
                $twig->load($template);
            }
            if ($surface === ThemeSurface::Site) {
                $this->validateSiteShell($twig);
            } else {
                $this->validateAdministratorShell($twig);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException(sprintf(
                'The %s theme could not be compiled: %s',
                $surface->value,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * Render both public entries and verify the protected KIS 1.0 site-shell boundary.
     *
     * The template owns its complete visual composition and may use any DOM nesting outside these
     * recovery-critical semantics. Synthetic host data proves each entry independently retains a valid
     * document, host assets, a matching skip target, and server-rendered current navigation.
     *
     * @param   Environment  $twig  Candidate environment with theme, core, and KIS namespaces registered.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When either public entry omits a protected KIS 1.0 invariant.
     *
     * @since   2.0.0
     */
    private function validateSiteShell(Environment $twig): void
    {
        foreach (['home.twig', 'page.twig'] as $entry) {
            $rendered = $twig->render($entry, $this->siteShellData());
            $this->validateSiteDocument($rendered, $entry);
        }
    }

    /**
     * Verify one rendered site entry retains the minimal public-shell invariants.
     *
     * @param   string  $rendered  Complete rendered HTML emitted by the candidate entry.
     * @param   string  $entry     Package-relative entry name used in actionable failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a protected document, asset, navigation, or skip invariant is absent.
     *
     * @since   2.0.0
     */
    private function validateSiteDocument(string $rendered, string $entry): void
    {
        $rendered = $this->visibleSemanticHtml($rendered);

        if (preg_match('/\A\s*<!doctype\s+html\s*>/i', $rendered) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render the HTML doctype required by KIS 1.0.',
                $entry,
            ));
        }

        $htmlAttributes = $this->firstOpeningTagAttributes($rendered, 'html');
        if ($htmlAttributes === null || trim((string) $this->attribute($htmlAttributes, 'lang')) === '') {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must declare a non-empty document language for KIS 1.0.',
                $entry,
            ));
        }

        if (!$this->hasTagAttributes($rendered, 'meta', ['charset' => 'utf-8'])) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must declare UTF-8 document encoding for KIS 1.0.',
                $entry,
            ));
        }

        $viewport = $this->metaContent($rendered, 'viewport');
        if (
            $viewport === null
            || preg_match('/(?:^|,)\s*width\s*=\s*device-width(?:\s*,|$)/i', $viewport) !== 1
            || preg_match('/(?:^|,)\s*initial-scale\s*=\s*1(?:\.0+)?(?:\s*,|$)/i', $viewport) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must declare a responsive width=device-width viewport for KIS 1.0.',
                $entry,
            ));
        }

        if (
            preg_match('/<title\b[^>]*>(?<content>.*?)<\/title\s*>/is', $rendered, $title) !== 1
            || trim(strip_tags(html_entity_decode($title['content'], ENT_QUOTES | ENT_HTML5))) === ''
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render a non-empty document title.',
                $entry,
            ));
        }

        if (
            !$this->hasTagAttributes($rendered, 'link', [
            'rel' => 'stylesheet',
            'href' => self::STYLESHEET_SENTINEL,
            ])
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render every host-supplied site stylesheet.',
                $entry,
            ));
        }

        if (
            !$this->hasTagAttributes($rendered, 'script', [
            'type' => 'module',
            'src' => self::MODULE_SENTINEL,
            ])
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render every host-supplied site module.',
                $entry,
            ));
        }

        $main = $this->firstElement($rendered, 'main');
        if (
            $main === null
            || ($entry === 'home.twig' && trim(strip_tags($main['content'])) === '')
            || (
                $entry === 'page.twig'
                && (
                    !str_contains($main['content'], self::CONTENT_SENTINEL)
                    || !str_contains($main['content'], self::BODY_SENTINEL)
                )
            )
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render its presentation-ready content inside the main landmark.',
                $entry,
            ));
        }
        $mainId = $this->attribute($main['attributes'], 'id');
        if ($mainId === null || trim($mainId) === '') {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render a main landmark with a stable skip target.',
                $entry,
            ));
        }
        if ($this->attribute($main['attributes'], 'tabindex') !== '-1') {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry main landmark must accept skip-link focus with tabindex="-1".',
                $entry,
            ));
        }
        if (!$this->hasTagAttributes($rendered, 'a', ['href' => '#' . $mainId])) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must link to its main landmark with a matching skip target.',
                $entry,
            ));
        }

        $navigation = $this->elementContaining($rendered, 'nav', self::NAVIGATION_SENTINEL);
        if (
            $navigation === null
            || (
                trim((string) $this->attribute($navigation['attributes'], 'aria-label')) === ''
                && trim((string) $this->attribute($navigation['attributes'], 'aria-labelledby')) === ''
            )
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must render labelled host-supplied navigation.',
                $entry,
            ));
        }

        $current = $this->elementContaining($navigation['content'], 'a', self::NAVIGATION_SENTINEL);
        if (
            $current === null
            || $this->attribute($current['attributes'], 'href') !== self::SITE_NAVIGATION_HREF
            || strtolower((string) $this->attribute($current['attributes'], 'aria-current')) !== 'page'
        ) {
            throw new InvalidArgumentException(sprintf(
                'The site %s entry must preserve the current host navigation destination and state.',
                $entry,
            ));
        }
    }

    /**
     * Build the protected public data used to render both site entries during activation.
     *
     * @return  array<string, mixed>  Site settings, one current navigation item, host assets, and a page entry.
     *
     * @since   2.0.0
     */
    private function siteShellData(): array
    {
        return [
            'site_name' => self::TITLE_SENTINEL,
            'site_logo' => '',
            'canonical_url' => self::SITE_NAVIGATION_HREF,
            'current_path' => self::SITE_NAVIGATION_HREF,
            'navigation' => [[
                'title' => self::NAVIGATION_SENTINEL,
                'href' => self::SITE_NAVIGATION_HREF,
                'children' => [],
            ]],
            'site_assets' => [
                'stylesheets' => [self::STYLESHEET_SENTINEL],
                'modules' => [self::MODULE_SENTINEL],
            ],
            'presentation' => SitePresentation::from(SitePresentation::defaults())->toView(),
            'entry' => [
                'id' => '00000000-0000-7000-8000-000000000001',
                'title' => self::CONTENT_SENTINEL,
                'slug' => 'kis-validator',
                'data' => [],
                'body_html' => '<p>' . self::BODY_SENTINEL . '</p>',
                'version' => 1,
            ],
        ];
    }

    /**
     * Require the candidate manifest to admit every KIS contract supplied by this host.
     *
     * @param   TemplateKisCompatibility  $compatibility  Closed, versioned declaration from the package manifest.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the standard, component version, or token version is unsupported.
     *
     * @since   2.0.0
     */
    private function validateCompatibility(TemplateKisCompatibility $compatibility): void
    {
        if ($compatibility->standard() !== self::KIS_STANDARD) {
            throw new InvalidArgumentException(sprintf(
                'The template requires unsupported KIS standard %s; this host provides %s.',
                $compatibility->standard(),
                self::KIS_STANDARD,
            ));
        }

        $components = SemanticVersion::fromString(self::KIS_COMPONENT_VERSION);
        if (!$compatibility->supportsComponents($components)) {
            throw new InvalidArgumentException(sprintf(
                'The template does not support host KIS component contract %s.',
                self::KIS_COMPONENT_VERSION,
            ));
        }

        $tokens = SemanticVersion::fromString(self::KIS_TOKEN_VERSION);
        if (!$compatibility->supportsTokens($tokens)) {
            throw new InvalidArgumentException(sprintf(
                'The template does not support host KIS token contract %s.',
                self::KIS_TOKEN_VERSION,
            ));
        }
    }

    /**
     * Render and verify the protected KIS 1.0 administrator shell contract.
     *
     * The synthetic child proves block inheritance rather than trusting source-text matches. Synthetic
     * shell data then proves that a template consumes the host-owned navigation and asset outlets. The
     * assertions deliberately govern semantics and recovery-critical wiring only: theme markup, brand
     * presentation, component composition, and CSS remain replaceable within that boundary.
     *
     * @param   Environment  $twig  Candidate environment with theme, core, and KIS namespaces registered.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the rendered shell omits a protected KIS 1.0 invariant.
     *
     * @since   2.0.0
     */
    private function validateAdministratorShell(Environment $twig): void
    {
        $rendered = $twig->createTemplate(
            '{% extends "@admin-theme/layout.twig" %}'
            . '{% block title %}' . self::TITLE_SENTINEL . '{% endblock %}'
            . '{% block content %}<p>' . self::CONTENT_SENTINEL . '</p>{% endblock %}',
        )->render([
            'active_navigation' => self::NAVIGATION_ID,
            'administrator_assets' => [
                'stylesheets' => [self::STYLESHEET_SENTINEL],
                'modules' => [self::MODULE_SENTINEL],
            ],
            'administrator_commands_json' => '[]',
            'administrator_navigation' => [[
                'id' => self::NAVIGATION_ID,
                'workspace' => 'kis.validator.workspace',
                'label' => self::NAVIGATION_SENTINEL,
                'href' => self::NAVIGATION_HREF,
                'icon' => 'dashboard',
            ]],
            'administrator_workspaces' => [[
                'id' => 'kis.validator.workspace',
                'dom_id' => 'kis-validator-workspace',
                'label' => self::WORKSPACE_SENTINEL,
            ]],
            'csrf' => 'KUMWE_KIS_CSRF_SENTINEL',
        ]);
        $rendered = $this->visibleSemanticHtml($rendered);

        if (preg_match('/\A\s*<!doctype\s+html\s*>/i', $rendered) !== 1) {
            throw new InvalidArgumentException(
                'The administrator layout must render the HTML doctype required by KIS 1.0.',
            );
        }

        $htmlAttributes = $this->firstOpeningTagAttributes($rendered, 'html');
        if ($htmlAttributes === null || trim((string) $this->attribute($htmlAttributes, 'lang')) === '') {
            throw new InvalidArgumentException(
                'The administrator layout must declare a non-empty document language for KIS 1.0.',
            );
        }

        if (!$this->hasTagAttributes($rendered, 'meta', ['charset' => 'utf-8'])) {
            throw new InvalidArgumentException(
                'The administrator layout must declare UTF-8 document encoding for KIS 1.0.',
            );
        }

        $viewport = $this->metaContent($rendered, 'viewport');
        if (
            $viewport === null
            || preg_match('/(?:^|,)\s*width\s*=\s*device-width(?:\s*,|$)/i', $viewport) !== 1
            || preg_match('/(?:^|,)\s*initial-scale\s*=\s*1(?:\.0+)?(?:\s*,|$)/i', $viewport) !== 1
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must declare a responsive width=device-width viewport for KIS 1.0.',
            );
        }

        $colorScheme = $this->metaContent($rendered, 'color-scheme');
        if (
            $colorScheme === null
            || preg_match('/(?:^|\s)light(?:\s|$)/i', $colorScheme) !== 1
            || preg_match('/(?:^|\s)dark(?:\s|$)/i', $colorScheme) !== 1
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must advertise light and dark color schemes for KIS 1.0.',
            );
        }

        if (
            preg_match(
                '/<title\b[^>]*>[^<]*' . preg_quote(self::TITLE_SENTINEL, '/') . '[^<]*<\/title\s*>/i',
                $rendered,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must expose its title block inside the document title.',
            );
        }

        if (
            !$this->hasTagAttributes($rendered, 'link', [
            'rel' => 'stylesheet',
            'href' => self::STYLESHEET_SENTINEL,
            ])
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must render every host-supplied administrator stylesheet.',
            );
        }

        if (
            !$this->hasTagAttributes($rendered, 'script', [
            'type' => 'module',
            'src' => self::MODULE_SENTINEL,
            ])
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must render every host-supplied administrator module.',
            );
        }

        $main = $this->elementContaining($rendered, 'main', self::CONTENT_SENTINEL);
        if ($main === null) {
            throw new InvalidArgumentException(
                'The administrator layout must expose its content block inside a main landmark.',
            );
        }
        $mainId = $this->attribute($main['attributes'], 'id');
        if ($mainId === null || trim($mainId) === '') {
            throw new InvalidArgumentException(
                'The administrator layout main landmark must expose a stable skip-link target.',
            );
        }
        if ($this->attribute($main['attributes'], 'tabindex') !== '-1') {
            throw new InvalidArgumentException(
                'The administrator layout main landmark must accept skip-link focus with tabindex="-1".',
            );
        }
        if (!$this->hasTagAttributes($rendered, 'a', ['href' => '#' . $mainId])) {
            throw new InvalidArgumentException(
                'The administrator layout must link to its focusable main landmark before navigation.',
            );
        }

        $navigation = $this->elementContaining($rendered, 'nav', self::NAVIGATION_SENTINEL);
        if (
            $navigation === null
            || !str_contains($navigation['content'], self::WORKSPACE_SENTINEL)
            || (
                trim((string) $this->attribute($navigation['attributes'], 'aria-label')) === ''
                && trim((string) $this->attribute($navigation['attributes'], 'aria-labelledby')) === ''
            )
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must render labelled, capability-filtered workspace navigation.',
            );
        }

        $activeNavigation = $this->elementContaining($navigation['content'], 'a', self::NAVIGATION_SENTINEL);
        if (
            $activeNavigation === null
            || $this->attribute($activeNavigation['attributes'], 'href') !== self::NAVIGATION_HREF
            || strtolower((string) $this->attribute($activeNavigation['attributes'], 'aria-current')) !== 'page'
        ) {
            throw new InvalidArgumentException(
                'The administrator layout must preserve the active navigation destination and current state.',
            );
        }
    }

    /**
     * Reduce rendered markup to the visible semantic tree used by shell conformance checks.
     *
     * The bounded scanner removes comments, template contents, raw-text contents, and elements hidden
     * by standard HTML accessibility/presentation attributes. It preserves visible opening tags and
     * attributes, including host script outlets, so the existing invariant checks operate on what a
     * visitor and accessibility tree can actually reach rather than attacker-controlled inert carriers.
     *
     * @param   string  $html  Complete rendered candidate shell document.
     *
     * @return  string  Normalized visible semantic markup with inert carrier content removed.
     *
     * @throws  InvalidArgumentException  When rendered markup exceeds the validation budget or has an
     *          unterminated comment or tag.
     *
     * @since   2.0.0
     */
    private function visibleSemanticHtml(string $html): string
    {
        if (strlen($html) > 2_097_152) {
            throw new InvalidArgumentException('A rendered theme template cannot exceed two mebibytes.');
        }

        $visible = '';
        /** @var list<array{name: string, suppressed: bool}> $stack */
        $stack = [];
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $opening = strpos($html, '<', $offset);
            if ($opening === false) {
                if (!$this->suppressed($stack)) {
                    $visible .= substr($html, $offset);
                }
                break;
            }
            if ($opening > $offset && !$this->suppressed($stack)) {
                $visible .= substr($html, $offset, $opening - $offset);
            }

            if (substr($html, $opening, 4) === '<!--') {
                $closing = strpos($html, '-->', $opening + 4);
                if ($closing === false) {
                    throw new InvalidArgumentException('A rendered theme template contains an unterminated comment.');
                }
                $offset = $closing + 3;
                continue;
            }

            $end = $this->htmlTagEnd($html, $opening);
            if ($end === null) {
                throw new InvalidArgumentException('A rendered theme template contains an unterminated HTML tag.');
            }
            $tag = substr($html, $opening, $end - $opening + 1);

            if (preg_match('/^<!doctype\b/i', $tag) === 1) {
                if (!$this->suppressed($stack)) {
                    $visible .= $tag;
                }
                $offset = $end + 1;
                continue;
            }

            if (preg_match('/^<\s*\/\s*(?<name>[A-Za-z][A-Za-z0-9:-]*)/D', $tag, $closingTag) === 1) {
                $name = strtolower($closingTag['name']);
                $match = null;
                for ($index = count($stack) - 1; $index >= 0; --$index) {
                    if ($stack[$index]['name'] === $name) {
                        $match = $index;
                        break;
                    }
                }
                if ($match === null) {
                    if (!$this->suppressed($stack)) {
                        $visible .= $tag;
                    }
                } else {
                    $element = $stack[$match];
                    $ancestors = array_slice($stack, 0, $match);
                    $stack = $ancestors;
                    if (!$element['suppressed'] && !$this->suppressed($ancestors)) {
                        $visible .= $tag;
                    }
                }
                $offset = $end + 1;
                continue;
            }

            if (preg_match('/^<\s*(?<name>[A-Za-z][A-Za-z0-9:-]*)/D', $tag, $openingTag) !== 1) {
                $offset = $end + 1;
                continue;
            }

            $name = strtolower($openingTag['name']);
            $attributes = preg_replace(
                '/^<\s*' . preg_quote($name, '/') . '\b|\/?>$/i',
                '',
                $tag,
            );
            $attributes = is_string($attributes) ? $attributes : '';
            $suppressed = $this->suppressed($stack) || $this->nonPresentational($name, $attributes);

            if (in_array($name, ['script', 'style', 'textarea', 'title', 'noscript'], true)) {
                $rawClosing = [];
                if (
                    preg_match(
                        '/<\/\s*' . preg_quote($name, '/') . '\s*>/i',
                        $html,
                        $rawClosing,
                        PREG_OFFSET_CAPTURE,
                        $end + 1,
                    ) !== 1
                ) {
                    $offset = $length;
                    continue;
                }
                $closingSource = $rawClosing[0][0];
                $closingOffset = $rawClosing[0][1];
                if (!$suppressed) {
                    $visible .= $tag;
                    if ($name === 'title') {
                        $visible .= htmlspecialchars(
                            substr($html, $end + 1, $closingOffset - $end - 1),
                            ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                            'UTF-8',
                        );
                    }
                    $visible .= $closingSource;
                }
                $offset = $closingOffset + strlen($closingSource);
                continue;
            }

            if (!$suppressed) {
                $visible .= $tag;
            }
            if (
                preg_match('/\/\s*>$/D', $tag) !== 1
                && !in_array($name, [
                    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
                    'meta', 'param', 'source', 'track', 'wbr',
                ], true)
            ) {
                $stack[] = ['name' => $name, 'suppressed' => $suppressed];
            }
            $offset = $end + 1;
        }

        return $visible;
    }

    /**
     * Find the closing bracket of one HTML tag without treating a quoted bracket as structural.
     *
     * @param   string  $html     Rendered document being scanned.
     * @param   int     $opening  Byte offset of the tag's opening bracket.
     *
     * @return  ?int  Byte offset of the structural closing bracket, or null when none exists.
     *
     * @since   2.0.0
     */
    private function htmlTagEnd(string $html, int $opening): ?int
    {
        $quote = null;
        $length = strlen($html);
        for ($offset = $opening + 1; $offset < $length; ++$offset) {
            $character = $html[$offset];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '>') {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Report whether the current scanner position is inside a non-presentational ancestor.
     *
     * @param   list<array{name: string, suppressed: bool}>  $stack  Open element stack in document order.
     *
     * @return  bool  True when any open ancestor is suppressed from the visible semantic view.
     *
     * @since   2.0.0
     */
    private function suppressed(array $stack): bool
    {
        foreach ($stack as $element) {
            if ($element['suppressed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether an element is an inert or hidden carrier rather than visible presentation.
     *
     * @param   string  $name        Lowercase HTML element name.
     * @param   string  $attributes  Raw source attributes without angle brackets.
     *
     * @return  bool  True for templates and standard hidden, inert, or hidden-input declarations.
     *
     * @since   2.0.0
     */
    private function nonPresentational(string $name, string $attributes): bool
    {
        if ($name === 'template' || $this->booleanAttribute($attributes, 'hidden')) {
            return true;
        }
        if ($this->booleanAttribute($attributes, 'inert')) {
            return true;
        }
        if (strtolower((string) $this->attribute($attributes, 'aria-hidden')) === 'true') {
            return true;
        }
        if ($name === 'input' && strtolower((string) $this->attribute($attributes, 'type')) === 'hidden') {
            return true;
        }

        $style = $this->attribute($attributes, 'style');
        return $style !== null && preg_match(
            '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|'
            . 'content-visibility\s*:\s*hidden|opacity\s*:\s*0(?:\.0+)?)'
            . '(?:\s*!important)?\s*(?:;|$)/i',
            $style,
        ) === 1;
    }

    /**
     * Detect a boolean HTML attribute whether it is bare or assigned a quoted value.
     *
     * @param   string  $attributes  Raw source attributes without angle brackets.
     * @param   string  $name        Safe validator-owned attribute name.
     *
     * @return  bool  True when the boolean attribute is present as a distinct attribute.
     *
     * @since   2.0.0
     */
    private function booleanAttribute(string $attributes, string $name): bool
    {
        return preg_match(
            '/(?:^|\s)' . preg_quote($name, '/') . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s]+))?(?=\s|$)/i',
            trim($attributes),
        ) === 1;
    }

    /**
     * Return one element whose inner markup contains the requested sentinel.
     *
     * @param   string  $html      Rendered HTML to inspect.
     * @param   string  $element   Safe, validator-owned HTML element name.
     * @param   string  $sentinel  Unique rendered text expected inside the element.
     *
     * @return  ?array{attributes: string, content: string}  Matching raw attributes and inner markup.
     *
     * @since   2.0.0
     */
    private function elementContaining(string $html, string $element, string $sentinel): ?array
    {
        $matches = [];
        preg_match_all(
            '/<' . preg_quote($element, '/') . '\b(?<attributes>[^>]*)>(?<content>.*?)<\/'
            . preg_quote($element, '/') . '\s*>/is',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            if (str_contains($match['content'], $sentinel)) {
                return ['attributes' => $match['attributes'], 'content' => $match['content']];
            }
        }

        return null;
    }

    /**
     * Return the first complete element of a requested type.
     *
     * @param   string  $html     Rendered HTML to inspect.
     * @param   string  $element  Safe, validator-owned HTML element name.
     *
     * @return  ?array{attributes: string, content: string}  First match, or null when no element exists.
     *
     * @since   2.0.0
     */
    private function firstElement(string $html, string $element): ?array
    {
        if (
            preg_match(
                '/<' . preg_quote($element, '/') . '\b(?<attributes>[^>]*)>(?<content>.*?)<\/'
                . preg_quote($element, '/') . '\s*>/is',
                $html,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return ['attributes' => $match['attributes'], 'content' => $match['content']];
    }

    /**
     * Read raw attributes from the first opening tag of a given element.
     *
     * @param   string  $html     Rendered HTML to inspect.
     * @param   string  $element  Safe, validator-owned HTML element name.
     *
     * @return  ?string  Attribute source without angle brackets, or null when the tag is absent.
     *
     * @since   2.0.0
     */
    private function firstOpeningTagAttributes(string $html, string $element): ?string
    {
        if (
            preg_match(
                '/<' . preg_quote($element, '/') . '\b(?<attributes>[^>]*)>/i',
                $html,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return $match['attributes'];
    }

    /**
     * Determine whether one opening tag carries every requested literal attribute value.
     *
     * @param   string                 $html        Rendered HTML to inspect.
     * @param   string                 $element     Safe, validator-owned HTML element name.
     * @param   array<string, string>  $attributes  Attribute names and decoded values that must coexist.
     *
     * @return  bool  True when a matching opening tag exists.
     *
     * @since   2.0.0
     */
    private function hasTagAttributes(string $html, string $element, array $attributes): bool
    {
        $matches = [];
        preg_match_all(
            '/<' . preg_quote($element, '/') . '\b(?<attributes>[^>]*)>/i',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $source = $match['attributes'];
            foreach ($attributes as $name => $value) {
                $actual = $this->attribute($source, $name);
                if ($actual === null || strcasecmp($actual, $value) !== 0) {
                    continue 2;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Read a quoted attribute value from raw opening-tag attributes.
     *
     * KIS templates are required to quote attribute values. Decoding entities lets the conformance
     * checks compare the semantic destination rather than the serializer's chosen entity spelling.
     *
     * @param   string  $attributes  Raw opening-tag attributes without angle brackets.
     * @param   string  $name        Safe, validator-owned attribute name.
     *
     * @return  ?string  Decoded value, or null when the attribute is absent or unquoted.
     *
     * @since   2.0.0
     */
    private function attribute(string $attributes, string $name): ?string
    {
        if (
            preg_match(
                '/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(?<value>.*?)\1/is',
                $attributes,
                $match,
            ) !== 1
        ) {
            return null;
        }

        return html_entity_decode($match['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Read the content value of a named metadata element.
     *
     * @param   string  $html  Rendered HTML to inspect.
     * @param   string  $name  Metadata name whose content is required.
     *
     * @return  ?string  Decoded metadata content, or null when no matching declaration exists.
     *
     * @since   2.0.0
     */
    private function metaContent(string $html, string $name): ?string
    {
        $matches = [];
        preg_match_all('/<meta\b(?<attributes>[^>]*)>/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes = $match['attributes'];
            $metaName = $this->attribute($attributes, 'name');
            if ($metaName !== null && strcasecmp($metaName, $name) === 0) {
                return $this->attribute($attributes, 'content');
            }
        }

        return null;
    }

    /**
     * List the templates a surface cannot render without.
     *
     * @param   ThemeSurface  $surface  Surface the theme is being activated on.
     *
     * @return  list<string>  Package-relative template names that must exist as regular files.
     *
     * @since   2.0.0
     */
    private function requiredEntries(ThemeSurface $surface): array
    {
        return $surface === ThemeSurface::Site ? ['home.twig', 'page.twig'] : ['layout.twig'];
    }

    /**
     * Collect every Twig template the package ships, so validation compiles all of them.
     *
     * Symlinked files are skipped instead of followed, which keeps a package from pulling templates in
     * from outside its own directory. The list is sorted so two runs over the same package report the
     * same failure first.
     *
     * @param   string  $root  Resolved theme directory to walk.
     *
     * @return  list<string>  Template paths relative to the root, slash separated and sorted.
     *
     * @since   2.0.0
     */
    private function templates(string $root): array
    {
        $templates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }
            if (strtolower($item->getExtension()) === 'twig') {
                $templates[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            }
        }

        sort($templates, SORT_STRING);

        return $templates;
    }
}
