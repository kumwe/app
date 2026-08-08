<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use RuntimeException;
use Throwable;

class BusinessRecordException extends RuntimeException
{
    public function __construct(
        private readonly string $stableCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }
}
