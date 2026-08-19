<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Records a completed restore drill as the evidence a data-destroying schema plan must cite to be approved.
 *
 * A plan whose risk demands recovery evidence cannot be approved on an operator's word that a backup exists;
 * it has to name a drill that was actually performed against the schema this plan would replace. That is what
 * this screen files. The claim is deliberately narrow: the evidence is bound to the plan's source schema
 * checksum and stamped with the live database driver, server version and application release read from the
 * environment rather than from the form, so a drill run against a different installation, engine or release
 * cannot later be cited here. Every one of the four clean-target proofs must be confirmed, and the operator
 * re-proves their password, because filing false evidence is what would let a destructive approval through.
 *
 * The route is mounted on `POST /administrator/business-schema-plans/recovery-evidence` behind the CSRF
 * middleware and demands `business.schema.recover`. Whether the filed evidence then satisfies a particular
 * plan — freshness, verifier, environment match — is decided again at approval time, not here.
 *
 * @since  2.0.0
 */
final readonly class RecordBusinessSchemaRecoveryEvidenceHandler implements RequestHandlerInterface
{
    /**
     * Wire the drill form to the service that stores evidence and to the facts it is stamped with.
     *
     * @param  BusinessSchemaService      $schemas      Loads the plan, then authorizes and persists the evidence.
     * @param  BusinessSchemaEnvironment  $environment  Supplies the driver, server version and release to stamp.
     * @param  HighImpactCredentialGuard  $credentials  Re-proves the operator's password before filing.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schemas,
        private BusinessSchemaEnvironment $environment,
        private HighImpactCredentialGuard $credentials,
    ) {
    }

    /**
     * File one drill as recovery evidence for the schema the named plan would replace.
     *
     * The plan is read only to obtain the source schema checksum the evidence binds to, so a plan that
     * installs a definition for the first time — which has no schema to restore — is refused outright. The
     * proofs, the verifier and the tested flag are written from what this handler established rather than
     * copied from the form, leaving the operator to supply only the drill's own identifying facts.
     *
     * @param   ServerRequestInterface  $request  Administrator POST carrying `plan_id`, the four proof
     *          checkboxes, `backup_manifest_checksum`, `backup_created_at`, `verified_at`, `drill_reference`,
     *          `client_version`, `restore_target_reference` and `current_password`.
     *
     * @return  ResponseInterface  A 303 redirect to the plans screen with an `evidence-recorded` notice and
     *          the new evidence identifier, which is what an approval cites.
     *
     * @throws  InvalidArgumentException  When a required field is missing or blank, a timestamp cannot be
     *          read, a clean-target proof was not confirmed, or the plan has no installed source schema.
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not read schema
     *          plans or may not record recovery evidence.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When no plan with that identifier
     *          belongs to this site.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the password step-up
     *          fails.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When a submitted checksum, reference or
     *          timestamp breaks the evidence document's own rules.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict  When the drill does not match the
     *          authenticated site, environment and verifier, or is dated in the future.
     *
     * @since   2.0.0
     */
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

        return BusinessSchemaAdministratorRequest::redirect(
            $planId,
            'evidence-recorded',
            $evidence->id,
            BusinessSchemaAdministratorRequest::activeTab($form['return_tab'] ?? null, 'recovery'),
        );
    }
}
