<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

/** Exact active installation generation observed by the mutation's current locking read. */
final readonly class BusinessRecordMutationGeneration
{
    public function __construct(
        public string $definitionId,
        public string $siteIdentifier,
        public string $ownerIdentifier,
        public int $definitionVersion,
        public string $definitionChecksum,
        public string $schemaChecksum,
        public SchemaInstallationStatus $status,
    ) {
    }

    public function assertMatches(ResolvedBusinessDefinition $resolved, bool $historyOnly = false): void
    {
        $installation = $resolved->installation;
        $allowed = $historyOnly
            ? [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ]
            : [SchemaInstallationStatus::Active];
        if (
            $installation->definitionId !== $this->definitionId
            || $installation->siteIdentifier !== $this->siteIdentifier
            || $installation->ownerIdentifier !== $this->ownerIdentifier
            || $installation->definitionVersion !== $this->definitionVersion
            || !hash_equals($installation->definitionChecksum, $this->definitionChecksum)
            || !hash_equals($installation->schemaChecksum, $this->schemaChecksum)
            || $installation->status !== $this->status
            || !in_array($this->status, $allowed, true)
        ) {
            throw new BusinessRecordTemporarilyUnavailable();
        }
    }
}
