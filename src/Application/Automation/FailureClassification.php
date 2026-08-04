<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

enum FailureClassification: string
{
    case TRANSIENT = 'transient';
    case PERMANENT = 'permanent';
}
