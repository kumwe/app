<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CreateBusinessSchemaPurgePlanHandler implements RequestHandlerInterface
{
    public function __construct(
        private BusinessSchemaService $schemas,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $context = AdministratorRequest::context($request);
        $definitionId = AdministratorRequest::required($form, 'definition_id');
        if (!hash_equals($definitionId, AdministratorRequest::required($form, 'confirmation'))) {
            throw new InvalidArgumentException('Purge planning requires the exact installed definition ID.');
        }
        $this->credentials->assertCurrentPassword(
            $context,
            'business.schema.purge-plan',
            BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
        );
        $plan = $this->schemas->createPurgePlan($context, $definitionId);

        return BusinessSchemaAdministratorRequest::redirect($plan->id, 'purge-planned');
    }
}
