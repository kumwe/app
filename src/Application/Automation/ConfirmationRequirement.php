<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

enum ConfirmationRequirement: string
{
    case NONE = 'none';
    case EXPLICIT = 'explicit';
}
