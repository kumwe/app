<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;

interface BusinessRecordRevisionRepository
{
    public function append(BusinessRecordRevision $revision): void;

    /** @return list<BusinessRecordRevision> */
    public function history(
        string $definitionId,
        string $recordKey,
        int $limit,
        ?int $beforeVersion = null,
    ): array;

    /** @return list<BusinessRecordRevision> */
    public function historyByIdentityDigest(
        string $definitionId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        string $recordIdentityDigest,
        int $limit,
        ?int $beforeVersion = null,
    ): array;
}
