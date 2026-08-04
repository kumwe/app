<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use RuntimeException;

final class VersionConflict extends RuntimeException
{
    public function __construct(int $expected, int $actual)
    {
        parent::__construct(sprintf('Expected version %d, but the current version is %d.', $expected, $actual));
    }
}
