<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Twig;

use Twig\Environment;

/**
 * Twig environment wired for the public front end of one site, isolated from the back office.
 *
 * The class name is the isolation boundary: the container shares one environment per surface under
 * its own type, so a collaborator that asks for this type can only ever render site templates. Its
 * loader chain searches the theme activated for the requested `SiteContext` first, then the built-in
 * site templates under `@core-site`, then active extension site views under their per-extension
 * namespaces. No administrator path or namespace is registered, so a front-end template cannot reach
 * back-office markup even if a theme names it. Obtain one from `IsolatedTwigEnvironmentFactory::site()`
 * rather than constructing it, since the loader chain is what makes the isolation hold.
 *
 * @since  2.0.0
 */
final class SiteTwigEnvironment extends Environment
{
}
