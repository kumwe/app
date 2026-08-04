<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

use RuntimeException;

final class ContentNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Content entry %s was not found.', $id));
    }
}
