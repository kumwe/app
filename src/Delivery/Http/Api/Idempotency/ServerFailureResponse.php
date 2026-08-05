<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ServerFailureResponse extends RuntimeException
{
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The idempotent operation returned an unsuccessful server response.');
    }
}
