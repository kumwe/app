<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\TemplateKisCompatibility;
use Kumwe\CMS\Presentation\Application\ThemePackageValidator;
use Kumwe\CMS\Presentation\ThemeSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies theme activation rejects broken packages and protected administrator-shell omissions.
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
     * Proves site themes retain complete markup authority rather than inheriting administrator KIS rules.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidSiteContractRemainsFullyOverridable(): void
    {
        file_put_contents($this->root . '/theme/home.twig', '<article>Custom home</article>');
        file_put_contents($this->root . '/theme/page.twig', '{{ title|default("Custom page") }}');

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());

        self::addToAssertionCount(1);
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
     * Proves a site theme cannot activate without both public entry views.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingRequiredEntryIsRejected(): void
    {
        file_put_contents($this->root . '/theme/home.twig', 'home');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page.twig');

        $this->validator()->validate($this->root . '/theme', ThemeSurface::Site, $this->compatibility());
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
