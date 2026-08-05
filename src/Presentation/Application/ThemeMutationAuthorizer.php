<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Presentation\ThemeSurface;

interface ThemeMutationAuthorizer
{
    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void;
}
