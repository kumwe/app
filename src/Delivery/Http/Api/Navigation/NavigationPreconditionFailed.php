<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use DomainException;

final class NavigationPreconditionFailed extends DomainException
{
    public function __construct()
    {
        parent::__construct('The supplied navigation ETag does not match the current version.');
    }
}
