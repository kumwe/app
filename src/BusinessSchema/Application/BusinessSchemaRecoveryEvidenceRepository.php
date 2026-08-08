<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSchema\Domain\SchemaRecoveryEvidence;

interface BusinessSchemaRecoveryEvidenceRepository
{
    public function find(SiteContext $site, string $evidenceId): ?SchemaRecoveryEvidence;

    public function save(SchemaRecoveryEvidence $evidence): void;
}
