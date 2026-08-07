<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use RuntimeException;
use Throwable;

final class BusinessDefinitionRevisionConflict extends RuntimeException
{
    public function __construct(public readonly int $expected, public readonly int $actual, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'Business-definition draft revision %d is stale; current revision is %d.',
            $expected,
            $actual,
        ), 0, $previous);
    }
}
