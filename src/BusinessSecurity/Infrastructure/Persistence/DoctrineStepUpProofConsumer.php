<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Application\Authorization\AuthenticatedSurface;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\StepUpProof;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalDenied;
use Kumwe\CMS\BusinessSecurity\Application\Approval\StepUpProofConsumer;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Doctrine replay fence for persisted, short-lived step-up proofs.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStepUpProofConsumer implements StepUpProofConsumer
{
    /**
     * Bind proof consumption to the installation database.
     *
     * @param  Connection  $database  Installation connection.
     * @param  TableNames  $tables    Portable table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Atomically consume one live proof after revalidating every persisted binding and user epoch.
     *
     * @param   StepUpProof       $proof    Proof attached to the current context.
     * @param   ExecutionContext  $context  Actor, rotated session and exact scope.
     * @param   string            $purpose  Exact protected operation purpose.
     * @param   DateTimeImmutable $at       Trusted current time.
     *
     * @return  string  Persisted proof UUID for audit and vote binding.
     *
     * @since   2.0.0
     */
    public function consume(
        StepUpProof $proof,
        ExecutionContext $context,
        string $purpose,
        DateTimeImmutable $at,
    ): string
    {
        $sessionId = $context->sessionId();
        $principal = $context->principal();
        if (
            $sessionId === null
            || $principal === null
            || $proof->purpose() !== $purpose
            || $proof->workspace()?->identifier() !== $context->workspace()?->identifier()
            || $proof->securityEpoch() !== $principal->securityEpoch()
            || !$proof->isValidFor(
                $context->actorId(),
                $sessionId,
                $context->site(),
                $context->organization(),
                $at,
            )
        ) {
            throw new ApprovalDenied();
        }
        $sessionTable = match ($context->surface()) {
            AuthenticatedSurface::Administrator => 'administrator_sessions',
            AuthenticatedSurface::Portal => 'portal_sessions',
            default => throw new ApprovalDenied(),
        };
        $sessionEpoch = $context->surface() === AuthenticatedSurface::Portal
            ? ' AND s.security_epoch = p.security_epoch'
            : '';
        $organization = $context->organization()?->identifier();
        $workspace = $context->workspace()?->identifier();
        $parameters = [
            hash('sha256', $proof->nonce()),
            $context->actorId(),
            $sessionId,
            $context->site()->identifier(),
            $organization,
            $organization,
            $workspace,
            $workspace,
            $purpose,
            $principal->securityEpoch(),
            $proof->method(),
            $at,
            $at,
        ];
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT p.id FROM %s p INNER JOIN %s u ON u.id = p.user_id '
            . 'INNER JOIN %s s ON s.id = p.session_id AND s.user_id = p.user_id '
            . 'WHERE p.nonce_digest = ? AND p.user_id = ? AND p.session_id = ? '
            . 'AND p.site_identifier = ? '
            . 'AND ((p.organization_identifier = ?) OR (p.organization_identifier IS NULL AND ? IS NULL)) '
            . 'AND ((p.workspace_identifier = ?) OR (p.workspace_identifier IS NULL AND ? IS NULL)) '
            . "AND p.purpose = ? AND p.security_epoch = ? AND u.security_epoch = p.security_epoch "
            . "AND u.status = 'active' AND p.method = ? AND p.expires_at > ? AND s.expires_at > ? "
            . 'AND s.site_identifier = p.site_identifier '
            . 'AND ((s.organization_identifier = p.organization_identifier) '
            . 'OR (s.organization_identifier IS NULL AND p.organization_identifier IS NULL)) '
            . 'AND ((s.workspace_identifier = p.workspace_identifier) '
            . 'OR (s.workspace_identifier IS NULL AND p.workspace_identifier IS NULL))%s '
            . 'AND p.consumed_at IS NULL AND p.revoked_at IS NULL%s',
            $this->tables->quoted('step_up_proofs'),
            $this->tables->quoted('users'),
            $this->tables->quoted($sessionTable),
            $sessionEpoch,
            $this->lockClause(),
        ), $parameters, [
            Types::STRING,
            Types::STRING,
            Types::GUID,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::BIGINT,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ]);
        $id = $row['id'] ?? null;
        if (!is_string($id)) {
            throw new ApprovalDenied();
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL AND revoked_at IS NULL',
            $this->tables->quoted('step_up_proofs'),
        ), [$at, $id], [Types::DATETIME_IMMUTABLE, Types::GUID]);
        if ($affected !== 1) {
            throw new ApprovalDenied();
        }

        return $id;
    }

    /** Return the platform row-lock suffix. @return string SQL lock suffix where supported. @since 2.0.0 */
    private function lockClause(): string
    {
        return $this->database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
