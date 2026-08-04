<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

enum ExtensionStatus: string
{
    case Disabled = 'disabled';
    case Active = 'active';
    case Failed = 'failed';
}
