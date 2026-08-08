<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;

/** Serializes record mutations with definition lifecycle and physical-schema execution. */
interface BusinessRecordMutationFence
{
    public function lock(
        ExecutionContext $context,
        string $definitionIdentifier,
    ): BusinessRecordMutationGeneration;

    public function shared(
        SiteContext $site,
        string $definitionIdentifier,
        bool $historyOnly = false,
    ): BusinessRecordMutationGeneration;
}
