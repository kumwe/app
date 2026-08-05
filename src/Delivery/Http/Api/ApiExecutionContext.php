<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Psr\Http\Message\ServerRequestInterface;

final class ApiExecutionContext
{
    public static function fromRequest(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('An authenticated execution context is required.');
        }
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (
            !$principal instanceof AuthenticatedPrincipal
            || $context->principal()?->subject() !== $principal->subject()
        ) {
            throw new InvalidArgumentException('The API principal and execution context identities must match.');
        }

        return $context;
    }
}
