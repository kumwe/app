<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Presentation;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\TemplateKisCompatibility;
use Kumwe\CMS\Extension\Domain\ThemeSurface;

/**
 * Contract for proving a candidate theme package can render before its activation is written.
 *
 * Application owns this contract for the same reason it owns `TransactionManager`: refusing a broken
 * theme before the registry write is part of the activation use case, while parsing and compiling the
 * package's templates is template-engine work that belongs behind the seam. The extension manager
 * consults this port after authorization and inside the activation flow, so a refused package aborts
 * activation with the registry untouched; `TwigThemePackageValidator` is the shipped adapter, and
 * nothing here names a template engine, an environment or a loader.
 *
 * An implementation owes the KIS 1.0 conformance guarantees: the manifest's declared compatibility must
 * admit every contract this host provides, the surface's entry templates must be ordinary files rather
 * than symlinks that could reach outside the package, every template the package ships must compile
 * against the same loader chain the renderer will use, and the rendered shells must retain the
 * protected document, asset, navigation and keyboard-recovery invariants.
 *
 * @since  2.0.0
 */
interface ThemePackageValidator
{
    /**
     * Assert that a theme directory compiles for the surface it is about to be activated on.
     *
     * @param   string                    $themePath      Directory holding this surface's templates
     *          inside the package.
     * @param   ThemeSurface              $surface        Surface the theme is being activated on.
     * @param   TemplateKisCompatibility  $compatibility  Versioned KIS contract declared in the signed
     *          manifest.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the compatibility is unsupported, the directory or an
     *          entry template is bad, a template does not compile, or a rendered shell omits a protected
     *          KIS 1.0 invariant.
     *
     * @since   2.0.0
     */
    public function validate(
        string $themePath,
        ThemeSurface $surface,
        TemplateKisCompatibility $compatibility,
    ): void;
}
