<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use RuntimeException;

final class BusinessSchemaNotFound extends RuntimeException
{
    public function __construct(string $subject)
    {
        parent::__construct('The requested business schema resource was not found: ' . $subject);
    }
}
