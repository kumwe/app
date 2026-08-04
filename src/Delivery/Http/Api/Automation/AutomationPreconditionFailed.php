<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Automation;

use DomainException;

final class AutomationPreconditionFailed extends DomainException
{
    public function __construct()
    {
        parent::__construct('The supplied schedule ETag does not match the current version.');
    }
}
