<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

final readonly class RecordBusinessSchemaRecoveryEvidenceHandler implements RequestHandlerInterface
{
    public function __construct(
        private BusinessSchemaService $schemas,
        private BusinessSchemaEnvironment $environment,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $context = AdministratorRequest::context($request);
        $planId = AdministratorRequest::required($form, 'plan_id');
        $plan = $this->schemas->plan($context, $planId);
        if ($plan->fromSchemaChecksum === null) {
            throw new InvalidArgumentException('Recovery evidence requires an installed source schema.');
        }
        foreach (
            [
                'clean_target_restore',
                'blueprint_checksum_verified',
                'typed_command_verified',
                'record_revision_audit_checksums_verified',
            ] as $proof
        ) {
            if (($form[$proof] ?? '') !== '1') {
                throw new InvalidArgumentException('Every clean-target recovery proof must be confirmed.');
            }
        }
        $this->credentials->assertCurrentPassword(
            $context,
            'business.schema.recovery-evidence',
            BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
        );
        $evidence = $this->schemas->recordRecoveryEvidence($context, new SchemaRecoveryEvidence(
            Uuid::uuid7()->toString(),
            $context->site()->identifier(),
            $this->environment->databaseDriver(),
            $this->environment->databaseServerVersion(),
            $this->environment->applicationRelease(),
            $plan->fromSchemaChecksum,
            AdministratorRequest::required($form, 'backup_manifest_checksum'),
            true,
            BusinessSchemaAdministratorRequest::date($form, 'backup_created_at'),
            BusinessSchemaAdministratorRequest::date($form, 'verified_at'),
            $context->actorId(),
            AdministratorRequest::required($form, 'drill_reference'),
            [
                'blueprint_checksum_verified' => true,
                'clean_target_restore' => true,
                'client_version' => AdministratorRequest::required($form, 'client_version'),
                'record_revision_audit_checksums_verified' => true,
                'restore_target_reference' => AdministratorRequest::required($form, 'restore_target_reference'),
                'typed_command_verified' => true,
            ],
        ));

        return BusinessSchemaAdministratorRequest::redirect($planId, 'evidence-recorded', $evidence->id);
    }
}
