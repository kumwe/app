<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SecretOnceResponseRollback extends RuntimeException
{
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The secret-returning request failed and was rolled back.');
    }
}
