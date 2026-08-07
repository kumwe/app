<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum DeleteBehavior: string
{
    case Restrict = 'restrict';
    case Cascade = 'cascade';
    case SetNull = 'set_null';
}
