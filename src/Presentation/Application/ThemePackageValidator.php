<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;
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
 * the protected KIS 1.0 shell contract. Site markup remains wholly theme-owned. Every failure is
 * reported as an `InvalidArgumentException`, which `DoctrineExtensionManager` lets abort activation.
 *
 * @since  2.0.0
 */
final readonly class ThemePackageValidator
{
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
     * failure type to handle and the operator still sees which template broke. On the administrator
     * surface the layout is additionally rendered against KIS 1.0 sentinels, since a theme that compiles
     * can still discard navigation, assets, responsive metadata, or the keyboard recovery path.
     *
     * @param   string        $themePath  Directory holding this surface's templates inside the package.
     * @param   ThemeSurface  $surface    Surface the theme is being activated on.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the directory, an entry template, or any Twig file is bad.
     *
     * @since   2.0.0
     */
    public function validate(string $themePath, ThemeSurface $surface): void
    {
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
            if ($surface === ThemeSurface::Administrator) {
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

        if (preg_match(
            '/<title\b[^>]*>[^<]*' . preg_quote(self::TITLE_SENTINEL, '/') . '[^<]*<\/title\s*>/i',
            $rendered,
        ) !== 1) {
            throw new InvalidArgumentException(
                'The administrator layout must expose its title block inside the document title.',
            );
        }

        if (!$this->hasTagAttributes($rendered, 'link', [
            'rel' => 'stylesheet',
            'href' => self::STYLESHEET_SENTINEL,
        ])) {
            throw new InvalidArgumentException(
                'The administrator layout must render every host-supplied administrator stylesheet.',
            );
        }

        if (!$this->hasTagAttributes($rendered, 'script', [
            'type' => 'module',
            'src' => self::MODULE_SENTINEL,
        ])) {
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
            $content = $match['content'] ?? null;
            $attributes = $match['attributes'] ?? null;
            if (is_string($content) && is_string($attributes) && str_contains($content, $sentinel)) {
                return ['attributes' => $attributes, 'content' => $content];
            }
        }

        return null;
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
        if (preg_match(
            '/<' . preg_quote($element, '/') . '\b(?<attributes>[^>]*)>/i',
            $html,
            $match,
        ) !== 1) {
            return null;
        }

        return is_string($match['attributes'] ?? null) ? $match['attributes'] : null;
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
            $source = $match['attributes'] ?? null;
            if (!is_string($source)) {
                continue;
            }
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
        if (preg_match(
            '/(?:^|\s)' . preg_quote($name, '/') . '\s*=\s*(["\'])(?<value>.*?)\1/is',
            $attributes,
            $match,
        ) !== 1) {
            return null;
        }

        $value = $match['value'] ?? null;
        return is_string($value) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
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
            $attributes = $match['attributes'] ?? null;
            if (!is_string($attributes)) {
                continue;
            }
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
