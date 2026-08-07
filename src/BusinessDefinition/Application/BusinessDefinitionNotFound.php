<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use RuntimeException;

final class BusinessDefinitionNotFound extends RuntimeException
{
    public function __construct(string $identifier, ?int $version = null)
    {
        parent::__construct(sprintf(
            'Business definition %s%s was not found.',
            $identifier,
            $version === null ? '' : ' version ' . $version,
        ));
    }
}
