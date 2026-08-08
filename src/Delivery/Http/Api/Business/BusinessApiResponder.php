<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps business definition and schema application failures onto RFC 9457 problem documents.
 *
 * The same exception must produce the same outcome on every delivery surface, so the mapping
 * lives here rather than in individual handlers. Unrecognised throwables are rethrown so a
 * genuine fault surfaces as a 500 instead of being flattened into a client error.
 */
final readonly class BusinessApiResponder
{
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    public function problem(Throwable $exception, string $instance): ResponseInterface
    {
        return match (true) {
            $exception instanceof AuthorizationDenied => $this->problems->create(
                403,
                'Forbidden',
                'The authenticated identity is not authorized for this operation.',
                'urn:kumwe:problem:authorization-denied',
                $instance,
            ),
            $exception instanceof InsufficientCapability => $this->problems->create(
                403,
                'Forbidden',
                $exception->getMessage(),
                'urn:kumwe:problem:insufficient-capability',
                $instance,
            ),
            $exception instanceof BusinessDefinitionNotFound => $this->problems->create(
                404,
                'Business Definition Not Found',
                $exception->getMessage(),
                'urn:kumwe:problem:business-definition-not-found',
                $instance,
            ),
            $exception instanceof BusinessSchemaNotFound => $this->problems->create(
                404,
                'Business Schema Not Found',
                $exception->getMessage(),
                'urn:kumwe:problem:business-schema-not-found',
                $instance,
            ),
            $exception instanceof BusinessDefinitionRevisionConflict => $this->problems->create(
                412,
                'Precondition Failed',
                $exception->getMessage(),
                'urn:kumwe:problem:precondition-failed',
                $instance,
            ),
            $exception instanceof BusinessSchemaConflict => $this->problems->create(
                409,
                'Conflict',
                $exception->getMessage(),
                'urn:kumwe:problem:business-schema-conflict',
                $instance,
            ),
            $exception instanceof InvalidBusinessDefinition,
            $exception instanceof InvalidBusinessSchema,
            $exception instanceof InvalidArgumentException,
            $exception instanceof DomainException => $this->problems->create(
                422,
                'Unprocessable Content',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                $instance,
            ),
            default => throw $exception,
        };
    }
}
