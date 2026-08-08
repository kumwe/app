<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;

interface BusinessRecordReadRepository
{
    /** @return list<BusinessRecord> */
    public function referencing(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
    ): array;

    public function identity(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeDeleted = false,
    ): ?StoredRecordIdentity;

    public function get(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): ?BusinessRecord;

    /** @param list<string> $projection */
    public function view(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecord $record,
        array $projection = [],
    ): BusinessRecordView;

    public function browse(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): RecordBrowseResult;

    public function ownedLineIdentity(
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        EntityTypeDefinition $lineDefinition,
        string $lineId,
    ): ?StoredRecordIdentity;
}
