<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Registry;

use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\ExtensionRecord;

interface ExtensionRegistry
{
    public function find(ExtensionIdentifier $identifier): ?ExtensionRecord;

    /** Persist only if the stored registry version equals $expectedVersion. */
    public function save(ExtensionRecord $extension, int $expectedVersion): void;
}
