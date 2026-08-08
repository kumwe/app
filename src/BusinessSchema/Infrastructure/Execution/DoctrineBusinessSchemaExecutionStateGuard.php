<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Execution;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaExecutionStateGuard;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/** Current locking reads used by the transaction that finalizes a physical-schema execution. */
final readonly class DoctrineBusinessSchemaExecutionStateGuard implements BusinessSchemaExecutionStateGuard
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function lockOwner(
        SiteContext $site,
        string $definitionId,
        string $ownerIdentifier,
        bool $activeRequired,
    ): bool {
        $this->assertTransaction();
        try {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT site_identifier, owner_identifier, owner_active FROM %s WHERE id = ? FOR UPDATE',
                $this->tables->quoted('business_definitions'),
            ), [$definitionId]);
        } catch (DbalException $failure) {
            throw new BusinessSchemaConflict('The definition owner could not be locked for finalization.', 0, $failure);
        }
        $active = $row !== false
            && in_array($row['owner_active'] ?? null, [true, 1, '1', 't', 'true'], true);
        if ($row === false
            || ($row['site_identifier'] ?? null) !== $site->identifier()
            || ($row['owner_identifier'] ?? null) !== $ownerIdentifier
            || ($activeRequired && !$active)) {
            throw new BusinessSchemaConflict('The definition owner changed during schema execution.');
        }

        return $active;
    }

    public function lockInstallationStatus(string $definitionId): ?SchemaInstallationStatus
    {
        $this->assertTransaction();
        try {
            $status = $this->database->fetchOne(sprintf(
                'SELECT status FROM %s WHERE definition_id = ? FOR UPDATE',
                $this->tables->quoted('business_schema_installations'),
            ), [$definitionId]);
        } catch (DbalException $failure) {
            throw new BusinessSchemaConflict(
                'The schema installation could not be locked for finalization.',
                0,
                $failure,
            );
        }
        if ($status === false) {
            return null;
        }
        if (!is_string($status) || SchemaInstallationStatus::tryFrom($status) === null) {
            throw new BusinessSchemaConflict('The locked schema installation status is invalid.');
        }

        return SchemaInstallationStatus::from($status);
    }

    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessSchemaConflict('Schema finalization requires an active database transaction.');
        }
    }
}
