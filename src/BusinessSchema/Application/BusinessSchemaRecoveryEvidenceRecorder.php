<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Security\HighImpactCredentialGuard;
use Kumwe\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\Context\Value\ExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * Files a completed restore drill as the evidence a data-destroying schema plan must cite to be approved.
 *
 * The administrator schema screen, `POST /api/v1/business-schema-plans/{id}/recovery-evidence` and
 * `bin/kumwe business-schema-evidence record` all file through this one use case, so the rules are the same on
 * every surface. The evidence binds to the plan's source schema checksum, a plan that installs a definition for
 * the first time having no schema to restore; all four clean-target proofs must be confirmed; the operator
 * re-proves their current password, because filing false evidence is what would let a destructive approval
 * through; and the database driver, server version and application release are stamped from the live
 * environment, never from the caller. `BusinessSchemaService::recordRecoveryEvidence()` then authorizes
 * `business.schema.recover`, matches the drill to the site, environment and verifier, and stores it.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaRecoveryEvidenceRecorder
{
    /**
     * The four clean-target proofs every drill must confirm, in the order the screen presents them.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array PROOFS = [
        'clean_target_restore',
        'blueprint_checksum_verified',
        'typed_command_verified',
        'record_revision_audit_checksums_verified',
    ];

    /**
     * Wire the recorder to the schema service, the environment it stamps and the password re-proof.
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
     * @param   ExecutionContext   $context                 Actor and site the drill is credited to.
     * @param   string             $planId                  Plan whose source schema the drill restored.
     * @param   list<string>       $confirmedProofs         Clean-target proofs the operator confirmed.
     * @param   string             $backupManifestChecksum  Checksum of the backup manifest restored.
     * @param   DateTimeImmutable  $backupCreatedAt         When the restored backup was taken.
     * @param   DateTimeImmutable  $verifiedAt              When the restore was verified.
     * @param   string             $drillReference          Operator reference of the drill.
     * @param   string             $clientVersion           Database client version the drill used.
     * @param   string             $restoreTargetReference  Clean target the backup was restored into.
     * @param   ?string            $currentPassword         The operator's current password, re-entered.
     *
     * @return  SchemaRecoveryEvidence  The stored evidence, whose identifier an approval cites.
     *
     * @throws  InvalidArgumentException  When the plan has no installed source schema or a proof is unconfirmed.
     * @throws  \Kumwe\Access\AuthorizationDenied  When the actor may not read plans or record evidence.
     * @throws  BusinessSchemaNotFound  When no plan with that identifier belongs to this site.
     * @throws  \Kumwe\App\Application\Security\HighImpactAuthenticationRequired  When the password re-proof
     *          fails.
     * @throws  \Kumwe\BusinessSchema\Domain\InvalidBusinessSchema  When a checksum, reference or timestamp breaks
     *          the evidence document's own rules.
     * @throws  BusinessSchemaConflict  When the drill does not match the site, environment and verifier.
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        string $planId,
        array $confirmedProofs,
        string $backupManifestChecksum,
        DateTimeImmutable $backupCreatedAt,
        DateTimeImmutable $verifiedAt,
        string $drillReference,
        string $clientVersion,
        string $restoreTargetReference,
        #[\SensitiveParameter] ?string $currentPassword,
    ): SchemaRecoveryEvidence {
        $plan = $this->schemas->plan($context, $planId);
        if ($plan->fromSchemaChecksum === null) {
            throw new InvalidArgumentException('Recovery evidence requires an installed source schema.');
        }
        if (array_diff(self::PROOFS, $confirmedProofs) !== []) {
            throw new InvalidArgumentException('Every clean-target recovery proof must be confirmed.');
        }
        $this->credentials->assertCurrentPassword($context, 'business.schema.recovery-evidence', $currentPassword);

        return $this->schemas->recordRecoveryEvidence($context, new SchemaRecoveryEvidence(
            Uuid::uuid7()->toString(),
            $context->site()->identifier(),
            $this->environment->databaseDriver(),
            $this->environment->databaseServerVersion(),
            $this->environment->applicationRelease(),
            $plan->fromSchemaChecksum,
            $backupManifestChecksum,
            true,
            $backupCreatedAt,
            $verifiedAt,
            $context->actorId(),
            $drillReference,
            [
                'blueprint_checksum_verified' => true,
                'clean_target_restore' => true,
                'client_version' => $clientVersion,
                'record_revision_audit_checksums_verified' => true,
                'restore_target_reference' => $restoreTargetReference,
                'typed_command_verified' => true,
            ],
        ));
    }
}
