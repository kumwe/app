<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Twig;

use Twig\Environment;

/**
 * Emergency back-office Twig environment built from the protected core templates alone.
 *
 * An activated administrator theme that fails to compile would otherwise lock operators out of the
 * back office, so this environment is composed without any theme path, extension view path, or
 * namespace beyond `@core-admin`: nothing an operator installs can change what it renders.
 * `AdministratorRenderer` falls back to it through `RecoveryAdministratorRenderer` whenever a themed
 * render raises a Twig error, which keeps the administrator usable while the broken theme is
 * recovered. Obtain one from `IsolatedTwigEnvironmentFactory::recoveryAdministrator()`.
 *
 * @since  2.0.0
 */
final class RecoveryAdministratorTwigEnvironment extends Environment
{
}
