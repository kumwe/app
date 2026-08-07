<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum IdentityStrategy: string
{
    case Uuid = 'uuid';
    case Reference = 'reference';
}
