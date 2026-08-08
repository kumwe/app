<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationGeneration;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;

/** Holds the installation row lock until the caller's record transaction commits or rolls back. */
final readonly class DoctrineBusinessRecordMutationFence implements BusinessRecordMutationFence
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
    ) {
    }

    public function lock(
        ExecutionContext $context,
        string $definitionIdentifier,
    ): BusinessRecordMutationGeneration {
        return $this->acquire($context->site(), $definitionIdentifier, 'FOR UPDATE', false);
    }

    public function shared(
        SiteContext $site,
        string $definitionIdentifier,
        bool $historyOnly = false,
    ): BusinessRecordMutationGeneration {
        $platform = $this->database->getDatabasePlatform();
        $clause = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'LOCK IN SHARE MODE',
            $platform instanceof PostgreSQLPlatform => 'FOR SHARE',
            default => throw new BusinessRecordTemporarilyUnavailable(),
        };

        return $this->acquire($site, $definitionIdentifier, $clause, $historyOnly);
    }

    private function acquire(
        SiteContext $site,
        string $definitionIdentifier,
        string $lockClause,
        bool $historyOnly,
    ): BusinessRecordMutationGeneration {
        if (!$this->database->isTransactionActive()) {
            throw new BusinessRecordTemporarilyUnavailable();
        }
        $uuid = Uuid::isValid($definitionIdentifier);
        $identity = $uuid ? '(h.id = ? OR h.handle = ?)' : 'h.handle = ?';
        $parameters = $uuid
            ? [$site->identifier(), $definitionIdentifier, $definitionIdentifier]
            : [$site->identifier(), $definitionIdentifier];
        try {
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT h.id AS definition_id, h.owner_identifier AS definition_owner, h.owner_active, '
                    . 'i.site_identifier, i.owner_identifier AS installation_owner, i.definition_version, '
                    . 'i.definition_checksum, i.schema_checksum, i.status FROM %s h INNER JOIN %s i '
                    . 'ON i.definition_id = h.id WHERE h.site_identifier = ? AND %s %s',
                $this->tables->quoted('business_definitions'),
                $this->tables->quoted('business_schema_installations'),
                $identity,
                $lockClause,
            ), $parameters);
        } catch (DbalException $failure) {
            throw new BusinessRecordTemporarilyUnavailable($failure);
        }
        if ($row === false) {
            try {
                $definition = $this->database->fetchAssociative(sprintf(
                    'SELECT h.owner_active FROM %s h WHERE h.site_identifier = ? AND %s %s',
                    $this->tables->quoted('business_definitions'),
                    $identity,
                    $lockClause,
                ), $parameters);
            } catch (DbalException $failure) {
                throw new BusinessRecordTemporarilyUnavailable($failure);
            }
            if ($definition === false) {
                throw new BusinessRecordDefinitionUnavailable();
            }
            if (!in_array($definition['owner_active'] ?? null, [true, 1, '1'], true)) {
                throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
            }
            throw new BusinessRecordSchemaUnavailable();
        }
        $ownerActive = $row['owner_active'] ?? null;
        if (!$historyOnly && !in_array($ownerActive, [true, 1, '1'], true)) {
            throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
        }
        $owner = $this->string($row, 'definition_owner');
        $status = SchemaInstallationStatus::tryFrom($this->string($row, 'status'));
        $allowed = $historyOnly
            ? [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ]
            : [SchemaInstallationStatus::Active];
        if (
            $this->string($row, 'site_identifier') !== $site->identifier()
            || $this->string($row, 'installation_owner') !== $owner
            || $status === null
            || !in_array($status, $allowed, true)
        ) {
            throw new BusinessRecordSchemaUnavailable();
        }

        return new BusinessRecordMutationGeneration(
            $this->string($row, 'definition_id'),
            $this->string($row, 'site_identifier'),
            $owner,
            $this->integer($row, 'definition_version'),
            $this->string($row, 'definition_checksum'),
            $this->string($row, 'schema_checksum'),
            $status,
        );
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new BusinessRecordSchemaUnavailable();
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int) $value;
        }

        throw new BusinessRecordSchemaUnavailable();
    }
}
