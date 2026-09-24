<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Delivery\Administrator;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRecorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records a completed restore drill as the evidence a data-destroying schema plan must cite to be approved.
 *
 * A plan whose risk demands recovery evidence cannot be approved on an operator's word that a backup exists;
 * it has to name a drill that was actually performed against the schema this plan would replace. That is what
 * this screen files. The claim is deliberately narrow: the evidence is bound to the plan's source schema
 * checksum and stamped with the live database driver, server version and application release read from the
 * environment rather than from the form, so a drill run against a different installation, engine or release
 * cannot later be cited here. Every one of the four clean-target proofs must be confirmed, and the operator
 * re-proves their password, because filing false evidence is what would let a destructive approval through. All of
 * that is `BusinessSchemaRecoveryEvidenceRecorder`, the use case REST and the console file through as well.
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
     * Wire the drill form to the recovery-evidence use case every surface files through.
     *
     * @param  BusinessSchemaRecoveryEvidenceRecorder  $evidence  Loads the plan, checks the proofs, re-proves the
     *         password, stamps the environment and stores the evidence.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessSchemaRecoveryEvidenceRecorder $evidence)
    {
    }

    /**
     * File one drill as recovery evidence for the schema the named plan would replace.
     *
     * The form supplies only the drill's own identifying facts and the four confirmed proofs; the plan's source
     * schema checksum, the verifier and the environment stamps come from `BusinessSchemaRecoveryEvidenceRecorder`.
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
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not read schema
     *          plans or may not record recovery evidence.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound  When no plan with that identifier
     *          belongs to this site.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the password step-up
     *          fails.
     * @throws  \Kumwe\BusinessSchema\Domain\InvalidBusinessSchema  When a submitted checksum, reference or
     *          timestamp breaks the evidence document's own rules.
     * @throws  \Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict  When the drill does not match the
     *          authenticated site, environment and verifier, or is dated in the future.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $form = AdministratorRequest::form($request);
        $planId = AdministratorRequest::required($form, 'plan_id');
        $evidence = $this->evidence->record(
            AdministratorRequest::context($request),
            $planId,
            array_values(array_filter(
                BusinessSchemaRecoveryEvidenceRecorder::PROOFS,
                static fn (string $proof): bool => ($form[$proof] ?? '') === '1',
            )),
            AdministratorRequest::required($form, 'backup_manifest_checksum'),
            BusinessSchemaAdministratorRequest::date($form, 'backup_created_at'),
            BusinessSchemaAdministratorRequest::date($form, 'verified_at'),
            AdministratorRequest::required($form, 'drill_reference'),
            AdministratorRequest::required($form, 'client_version'),
            AdministratorRequest::required($form, 'restore_target_reference'),
            BusinessSchemaAdministratorRequest::optional($form, 'current_password'),
        );

        return BusinessSchemaAdministratorRequest::redirect(
            $planId,
            'evidence-recorded',
            $evidence->id,
            BusinessSchemaAdministratorRequest::activeTab($form['return_tab'] ?? null, 'recovery'),
        );
    }
}
