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

final readonly class ApproveBusinessSchemaPlanHandler implements RequestHandlerInterface
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
        $planId = BusinessSchemaAdministratorRequest::planId($request);
        $plan = $this->schemas->plan($context, $planId);
        $expectedChecksum = AdministratorRequest::required($form, 'expected_checksum');
        $confirmation = BusinessSchemaAdministratorRequest::optional($form, 'confirmation');

        if ($plan->risk->requiresHighImpactAuthorization()) {
            if ($confirmation === null || !hash_equals($plan->checksum(), $confirmation)) {
                throw new InvalidArgumentException(
                    'High-impact approval requires the exact current 64-character plan checksum.',
                );
            }
            $this->credentials->assertCurrentPassword(
                $context,
                'business.schema.approve',
                BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
            );
        } else {
            $confirmation = null;
        }

        $this->schemas->approve(
            $context,
            $planId,
            $expectedChecksum,
            $confirmation,
            BusinessSchemaAdministratorRequest::optional($form, 'recovery_evidence_id'),
        );

        return BusinessSchemaAdministratorRequest::redirect($planId, 'approved');
    }
}
