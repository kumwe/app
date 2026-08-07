<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Extension\Runtime\ExtensionContainer;

interface ExtensionContributionProvider
{
    /** Called after every active provider registered services and before boot or route registration. */
    public function contribute(
        ExtensionContributionRegistrar $contributions,
        ExtensionContainer $container,
    ): void;
}
