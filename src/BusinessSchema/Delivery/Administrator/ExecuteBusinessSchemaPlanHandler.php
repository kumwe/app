<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ExecuteBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    public function __construct(private BusinessSchemaService $schemas)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $planId = BusinessSchemaAdministratorRequest::planId($request);
        $this->schemas->execute(AdministratorRequest::context($request), $planId);

        return BusinessSchemaAdministratorRequest::redirect($planId, 'executed');
    }
}
