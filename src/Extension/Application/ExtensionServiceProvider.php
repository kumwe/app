<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application;

use Kumwe\CMS\Extension\Runtime\ExtensionContainer;

interface ExtensionServiceProvider
{
    public function register(ExtensionContainer $container): void;
}
