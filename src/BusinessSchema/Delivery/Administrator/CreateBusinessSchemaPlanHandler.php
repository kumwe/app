<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CreateBusinessSchemaPlanHandler implements RequestHandlerInterface
{
    public function __construct(private BusinessSchemaService $schemas)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $plan = $this->schemas->createPlan(
            AdministratorRequest::context($request),
            AdministratorRequest::required($form, 'definition_id'),
        );

        return BusinessSchemaAdministratorRequest::redirect($plan->id, 'planned');
    }
}
