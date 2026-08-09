<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Twig;

use Twig\Environment;

/**
 * Twig environment wired for the back-office surface, isolated from the public front end.
 *
 * The class name is the isolation boundary: the container shares one environment per surface under
 * its own type, so a collaborator that asks for this type can only ever render administrator
 * templates. Its loader chain exposes the built-in administrator templates as `@core-admin`, active
 * extension administrator views under their per-extension namespaces, and the activated administrator
 * theme through a `ContractRestrictedLoader` that admits `layout.twig` alone — login views and
 * controller-specific pages therefore always resolve from core. Obtain one from
 * `IsolatedTwigEnvironmentFactory::administrator()`; when a themed render raises a Twig error,
 * `AdministratorRenderer` retries against `RecoveryAdministratorTwigEnvironment`.
 *
 * @since  2.0.0
 */
final class AdministratorTwigEnvironment extends Environment
{
}
