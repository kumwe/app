<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

enum ConditionType: string
{
    case Route = 'route';
    case Menu = 'menu';
    case Locale = 'locale';
    case Role = 'role';
}
