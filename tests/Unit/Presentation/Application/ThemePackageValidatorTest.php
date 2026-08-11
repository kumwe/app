<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\TemplateKisCompatibility;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\ThemeSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies theme activation rejects broken packages and protected public or administrator shell omissions.
 *
 * @since  2.0.0
 */
#[CoversClass(ThemePackageValidator::class)]
final class ThemePackageValidatorTest extends TestCase
{
    /**
     * Disposable template tree used by one test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Create isolated candidate and core template roots.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-validator-' . bin2hex(random_bytes(8));
        foreach (['/core/site', '/core/administrator', '/core/interface-standard', '/theme'] as $directory) {
            self::assertTrue(mkdir($this->root . $directory, 0700, true));
        }
        file_put_contents($this->root . '/core/administrator/layout.twig', 'core');
        file_put_contents($this->root . '/core/site/home.twig', 'home');
        file_put_contents($this->root . '/core/site/page.twig', 'page');
    }

    /**
     * Remove the disposable template tree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * Proves both complete site entries may customize markup inside the minimal public-shell boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidSiteContractRemainsFullyOverridable(): void
    {
        $this->writeValidSiteTheme();

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());

        self::addToAssertionCount(1);
    }

    /**
     * Proves every public entry is rendered and must preserve host module delivery independently.
     *
     * @param   string  $entry  Site entry name selected by the data provider.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('siteEntries')]
    public function testEverySiteEntryRequiresTheProtectedPublicShell(string $entry): void
    {
        $this->writeValidSiteTheme();
        $source = $entry === 'home.twig'
            ? $this->validSiteDocument('Invalid entry')
            : $this->validSitePageDocument();
        $invalid = str_replace(
            '{% for module in site_assets.modules %}'
            . '<script type="module" src="{{ module }}"></script>{% endfor %}',
            '',
            $source,
        );
        file_put_contents($this->root . '/theme/' . $entry, $invalid);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('site ' . $entry . ' entry');
        $this->expectExceptionMessage('host-supplied site module');

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());
    }

    /**
     * Proves the public contract enforces document, asset, keyboard, and navigation invariants.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('siteInvariantMutations')]
    public function testSiteEntryRejectsEveryProtectedInvariantCategory(
        string $search,
        string $replacement,
        string $message,
    ): void
    {
        $valid = $this->validSiteDocument('Contract probe');
        file_put_contents($this->root . '/theme/home.twig', str_replace($search, $replacement, $valid));
        file_put_contents($this->root . '/theme/page.twig', $this->validSitePageDocument());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());
    }

    /**
     * Proves a page cannot move presentation-ready entry content outside the first main landmark.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSitePageRequiresPresentedEntryInsideMainLandmark(): void
    {
        $this->writeValidSiteTheme();
        $page = str_replace(
            '<h1>{{ entry.title }}</h1><div>{{ entry.body_html|raw }}</div>',
            'Static decoration',
            $this->validSitePageDocument(),
        );
        $page = str_replace(
            '</main>',
            '</main><aside><h1>{{ entry.title }}</h1><div>{{ entry.body_html|raw }}</div></aside>',
            $page,
        );
        file_put_contents($this->root . '/theme/page.twig', $page);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page.twig entry must render its presentation-ready content');

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());
    }

    /**
     * Proves the first main landmark must retain both the presented page title and trusted body.
     *
     * @param   string  $omitted  Twig fragment to remove from the otherwise conforming page entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('presentedPageValues')]
    public function testSitePageRequiresEveryPresentedValueInsideMain(string $omitted): void
    {
        $this->writeValidSiteTheme();
        file_put_contents(
            $this->root . '/theme/page.twig',
            str_replace($omitted, '', $this->validSitePageDocument()),
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page.twig entry must render its presentation-ready content');

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());
    }

    /**
     * Proves activation fails closed when the signed declaration excludes a host KIS contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedKisCompatibilityIsRejectedBeforeTemplateCompilation(): void
    {
        $declarations = [
            [
                'contract' => 1,
                'standard' => 'kis-2.0',
                'components' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
                'tokens' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
            ],
            [
                'contract' => 1,
                'standard' => 'kis-1.0',
                'components' => ['minimum' => '2.0.0', 'maximum' => '2.1.0'],
                'tokens' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
            ],
            [
                'contract' => 1,
                'standard' => 'kis-1.0',
                'components' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
                'tokens' => ['minimum' => '0.8.0', 'maximum' => '0.9.0'],
            ],
        ];

        foreach ($declarations as $declaration) {
            try {
                $this->validator()->validate(
                    $this->root . '/theme',
                    ThemeSurface::Site,
                    TemplateKisCompatibility::fromArray($declaration),
                );
                self::fail('An unsupported KIS compatibility declaration reached template compilation.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('template', strtolower($exception->getMessage()));
            }
        }
    }

    /**
     * Proves schema-one compatibility preserves activation checks rather than bypassing validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacySchemaOneCompatibilityStillRequiresEverySiteEntry(): void
    {
        file_put_contents($this->root . '/theme/home.twig', 'home');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page.twig');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Site,
            $this->legacyCompatibility(),
        );
    }

    /**
     * Proves syntax failure in any packaged template aborts activation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidTwigIsRejected(): void
    {
        file_put_contents($this->root . '/theme/layout.twig', '{% invalid %}');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be compiled');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves KIS document metadata is an activation contract rather than a documentation suggestion.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresResponsiveViewportMetadata(): void
    {
        $layout = str_replace(
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '',
            $this->validAdministratorLayout(),
        );
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('responsive width=device-width viewport');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves a candidate cannot move the inherited page content outside the focusable main landmark.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresContentBlockInsideMainLandmark(): void
    {
        $layout = str_replace(
            '<main id="administrator-content" tabindex="-1">{% block content %}{% endblock %}</main>',
            '<main id="administrator-content" tabindex="-1"></main>{% block content %}{% endblock %}',
            $this->validAdministratorLayout(),
        );
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('content block inside a main landmark');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves the inherited document title cannot be discarded by a custom shell.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresTitleBlockInsideDocumentTitle(): void
    {
        $layout = str_replace(
            '<title>{% block title %}{% endblock %}</title>',
            '<title>Static title</title>{% block title %}{% endblock %}',
            $this->validAdministratorLayout(),
        );
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('title block inside the document title');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves the skip link and focusable target remain a single coherent keyboard journey.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresMatchingFocusableSkipTarget(): void
    {
        $layout = str_replace(
            'href="#administrator-content"',
            'href="#missing-content"',
            $this->validAdministratorLayout(),
        );
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('link to its focusable main landmark');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves a shell cannot replace the host-filtered navigation with hard-coded destinations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresCapabilityFilteredNavigationOutlet(): void
    {
        $layout = preg_replace(
            '/<nav\b.*?<\/nav>/s',
            '<nav aria-label="Administrator navigation"><a href="/administrator">Static</a></nav>',
            $this->validAdministratorLayout(),
        );
        self::assertIsString($layout);
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('capability-filtered workspace navigation');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves a shell cannot suppress host modules required by KIS interactions.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRequiresHostAssetOutlets(): void
    {
        $layout = str_replace(
            '{% for module in administrator_assets.modules %}'
            . '<script type="module" src="{{ module }}"></script>{% endfor %}',
            '',
            $this->validAdministratorLayout(),
        );
        file_put_contents($this->root . '/theme/layout.twig', $layout);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host-supplied administrator module');

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );
    }

    /**
     * Proves a complete KIS 1.0 administrator shell passes before activation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorShellRendersCompleteKisContract(): void
    {
        file_put_contents($this->root . '/theme/layout.twig', $this->validAdministratorLayout());

        $this->validator()->validate(
            $this->root . '/theme',
            ThemeSurface::Administrator,
            $this->compatibility(),
        );

        self::addToAssertionCount(1);
    }

    /**
     * Build the validator against the disposable core tree.
     *
     * @return  ThemePackageValidator
     *
     * @since   2.0.0
     */
    private function validator(): ThemePackageValidator
    {
        return new ThemePackageValidator($this->root . '/core');
    }

    /**
     * Return a compatibility declaration accepting the KIS contracts supplied by the test host.
     *
     * @return  TemplateKisCompatibility  Closed version-one compatibility declaration.
     *
     * @since   2.0.0
     */
    private function compatibility(): TemplateKisCompatibility
    {
        return TemplateKisCompatibility::fromArray([
            'contract' => 1,
            'standard' => 'kis-1.0',
            'components' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
            'tokens' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
        ]);
    }

    /**
     * Parse the exact compatibility default carried forward for schema-one template manifests.
     *
     * @return  TemplateKisCompatibility  Legacy default passed through the activation validator.
     *
     * @since   2.0.0
     */
    private function legacyCompatibility(): TemplateKisCompatibility
    {
        $manifest = ExtensionManifest::fromJson(<<<'JSON'
{
  "schema": 1,
  "name": "acme/legacy-template",
  "type": "template",
  "version": "1.0.0",
  "provider": "Acme\\LegacyTemplate\\Provider",
  "autoload": {"psr-4": {"Acme\\LegacyTemplate\\": "src/"}},
  "requires": {"kumwe": "^2.0.0", "php": "^8.5.0"}
}
JSON);
        $compatibility = $manifest->templateCompatibility();
        self::assertNotNull($compatibility);

        return $compatibility;
    }

    /**
     * Supply both independently rendered public entry names.
     *
     * @return  array<string, array{string}>  Entry cases keyed for readable PHPUnit output.
     *
     * @since   2.0.0
     */
    public static function siteEntries(): array
    {
        return [
            'fallback home' => ['home.twig'],
            'published page' => ['page.twig'],
        ];
    }

    /**
     * Supply one source mutation for every protected public-shell invariant category.
     *
     * @return  array<string, array{string, string, string}>  Search, replacement, and failure fragment.
     *
     * @since   2.0.0
     */
    public static function siteInvariantMutations(): array
    {
        return [
            'doctype' => ['<!doctype html>', '', 'HTML doctype'],
            'language' => ['<html lang="en">', '<html>', 'document language'],
            'encoding' => ['<meta charset="utf-8">', '', 'UTF-8'],
            'viewport' => [
                '<meta name="viewport" content="width=device-width, initial-scale=1">',
                '',
                'responsive width=device-width viewport',
            ],
            'title' => ['<title>{{ site_name }}</title>', '<title></title>', 'document title'],
            'stylesheet' => [
                '{% for stylesheet in site_assets.stylesheets %}'
                . '<link rel="stylesheet" href="{{ stylesheet }}">{% endfor %}',
                '',
                'host-supplied site stylesheet',
            ],
            'main content' => ['Contract probe', '', 'presentation-ready content'],
            'skip target' => ['href="#site-content"', 'href="#missing"', 'matching skip target'],
            'navigation label' => [
                'aria-label="Main navigation"',
                '',
                'labelled host-supplied navigation',
            ],
            'current navigation' => [
                ' aria-current="page"',
                '',
                'current host navigation destination and state',
            ],
        ];
    }

    /**
     * Supply each presentation-ready value a public page must keep inside its first main landmark.
     *
     * @return  array<string, array{string}>  Twig fragments keyed by the value their removal simulates.
     *
     * @since   2.0.0
     */
    public static function presentedPageValues(): array
    {
        return [
            'entry title' => ['<h1>{{ entry.title }}</h1>'],
            'trusted body' => ['<div>{{ entry.body_html|raw }}</div>'],
        ];
    }

    /**
     * Write two independently complete and visually customizable site entries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function writeValidSiteTheme(): void
    {
        file_put_contents($this->root . '/theme/home.twig', $this->validSiteDocument('Custom home'));
        file_put_contents($this->root . '/theme/page.twig', $this->validSitePageDocument());
    }

    /**
     * Return a complete page entry that renders both prepared title and trusted body inside main.
     *
     * @return  string  Standalone page Twig entry satisfying the protected public KIS shell.
     *
     * @since   2.0.0
     */
    private function validSitePageDocument(): string
    {
        return $this->validSiteDocument(
            '<h1>{{ entry.title }}</h1><div>{{ entry.body_html|raw }}</div>',
        );
    }

    /**
     * Return a complete site document satisfying the protected public KIS shell.
     *
     * @param   string  $content  Theme-owned main content proving entry customization remains free.
     *
     * @return  string  Standalone Twig entry with host asset, navigation, and recovery outlets.
     *
     * @since   2.0.0
     */
    private function validSiteDocument(string $content): string
    {
        return <<<'TWIG'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ site_name }}</title>
  {% for stylesheet in site_assets.stylesheets %}<link rel="stylesheet" href="{{ stylesheet }}">{% endfor %}
  {% for module in site_assets.modules %}<script type="module" src="{{ module }}"></script>{% endfor %}
</head>
<body>
  <a href="#site-content">Skip to content</a>
  <nav aria-label="Main navigation">
    {% for item in navigation %}
      <a href="{{ item.href }}"{% if current_path == item.href %} aria-current="page"{% endif %}>{{ item.title }}</a>
    {% endfor %}
  </nav>
  <main id="site-content">
TWIG
            . $content
            . <<<'TWIG'
</main>
</body>
</html>
TWIG;
    }

    /**
     * Return a minimal rendered shell satisfying every protected KIS 1.0 invariant.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private function validAdministratorLayout(): string
    {
        return <<<'TWIG'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>{% block title %}{% endblock %}</title>
  {% for stylesheet in administrator_assets.stylesheets %}<link rel="stylesheet" href="{{ stylesheet }}">{% endfor %}
  {% for module in administrator_assets.modules %}<script type="module" src="{{ module }}"></script>{% endfor %}
</head>
<body>
  <a href="#administrator-content">Skip to content</a>
  <nav aria-label="Administrator navigation">
    {% for workspace in administrator_workspaces %}
      <h2>{{ workspace.label }}</h2>
      {% for item in administrator_navigation %}
        {% if item.workspace == workspace.id %}
          <a href="{{ item.href }}"{% if active_navigation == item.id %} aria-current="page"{% endif %}>
            {{ item.label }}
          </a>
        {% endif %}
      {% endfor %}
    {% endfor %}
  </nav>
  <main id="administrator-content" tabindex="-1">{% block content %}{% endblock %}</main>
</body>
</html>
TWIG;
    }
}
