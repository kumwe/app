<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Business;

use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionNotFound;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionRevisionConflict;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaNotFound;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Maps business definition and schema application failures onto RFC 9457 problem documents.
 *
 * The same exception must produce the same outcome on every delivery surface, so the mapping
 * lives here rather than in individual handlers. Unrecognised throwables are rethrown so a
 * genuine fault surfaces as a 500 instead of being flattened into a client error.
 *
 * @since  2.0.0
 */
final readonly class BusinessApiResponder
{
    /**
     * Wire the responder to the factory that renders its problem documents.
     *
     * @param  ProblemDetailsResponseFactory  $problems  Builds the `application/problem+json` bodies sent back.
     *
     * @since  2.0.0
     */
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * Translate a business definition or schema failure into the response the API sends back.
     *
     * Authorization refusals become 403, a missing definition or schema 404, a definition revision
     * clash 412, a schema clash 409, and every validation failure 422. Only the authorization-denied
     * arm substitutes its own wording; the rest publish the exception message as the problem detail,
     * which is why those exceptions carry operator-safe messages. An exception matching no arm is
     * rethrown unchanged rather than mapped.
     *
     * @param   Throwable  $exception  Failure raised while a business definition or schema call ran.
     * @param   string     $instance   Request URI recorded as the problem document's `instance` member.
     *
     * @return  ResponseInterface  An `application/problem+json` response carrying the mapped status.
     *
     * @since   2.0.0
     */
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
