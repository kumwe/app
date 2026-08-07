<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Psr\Http\Server\RequestHandlerInterface;

interface AdministratorRouteHandlerFactory
{
    public function create(AdministratorRenderer $renderer): RequestHandlerInterface;
}
