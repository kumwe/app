<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

final class SecretAssociatedData
{
    public static function for(
        string $siteIdentifier,
        string $definitionId,
        string $recordId,
        string $field,
    ): string {
        return implode("\n", ['business-record-secret-v1', $siteIdentifier, $definitionId, $recordId, $field]);
    }

    private function __construct()
    {
    }
}
