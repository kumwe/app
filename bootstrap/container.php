<?php

/**
 * Default service container for every surface that does not need recovery wiring.
 *
 * `public/index.php` and the test suites require this file to obtain a fully wired container built
 * from the process environment.
 *
 * @return \Joomla\DI\Container The fully wired application container.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

return (new ContainerFactory())->create(Environment::fromGlobals());
