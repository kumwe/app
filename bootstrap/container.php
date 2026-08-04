<?php

declare(strict_types=1);

use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

return (new ContainerFactory())->create(Environment::fromGlobals());
