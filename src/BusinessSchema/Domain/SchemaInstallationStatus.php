<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

enum SchemaInstallationStatus: string
{
    case Installing = 'installing';
    case Active = 'active';
    case Disabled = 'disabled';
    case Preserved = 'preserved';
    case Failed = 'failed';
}
