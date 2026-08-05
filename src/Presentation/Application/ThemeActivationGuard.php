<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Presentation\ThemeSurface;

interface ThemeActivationGuard
{
    public function assertAllowed(
        ThemeSurface $surface,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void;
}
