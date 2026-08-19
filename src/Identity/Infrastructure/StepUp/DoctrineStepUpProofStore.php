<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\StepUp;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Identity\Application\StepUp\StepUpProofStore;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;

/**
 * DBAL issuer for short-lived, single-use, context-bound step-up proof replay fences.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStepUpProofStore implements StepUpProofStore
{
    /**
     * Bind proof issuance to the shared connection and installation table prefix.
     *
     * @param  Connection  $database  Shared DBAL connection participating in provider transactions.
     * @param  TableNames  $tables    Installation table-prefix mapper.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
    ) {
    }

    /**
     * Persist only a nonce digest and safe exact bindings, never the bearer nonce itself.
     *
     * @param   StepUpVerification  $verification  Fresh context-bound provider result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function issue(StepUpVerification $verification): void
    {
        $intent = $verification->intent;
        $this->database->insert($this->tables->raw('step_up_proofs'), [
            'id' => Uuid::uuid7()->toString(),
            'nonce_digest' => hash('sha256', $verification->nonce),
            'user_id' => $intent->subjectId,
            'session_id' => $verification->rotatedSession->sessionId,
            'site_identifier' => $intent->siteIdentifier,
            'organization_identifier' => $intent->organizationIdentifier,
            'workspace_identifier' => $intent->workspaceIdentifier,
            'purpose' => $intent->purpose,
            'security_epoch' => $intent->securityEpoch,
            'method' => $verification->method->value,
            'verified_at' => $verification->issuedAt,
            'expires_at' => $verification->expiresAt,
            'consumed_at' => null,
            'revoked_at' => null,
        ], [
            'verified_at' => Types::DATETIME_IMMUTABLE,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }
}
