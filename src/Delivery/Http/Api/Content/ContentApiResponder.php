<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Content;

use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Content\Application\ContentNotFound;
use Kumwe\App\Content\Application\ContentModelNotFound;
use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\App\Content\Domain\VersionConflict;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Turns a content API result or failure into the HTTP response the content routes send back.
 *
 * Every content handler answers in one of two shapes — the record document tagged with the entity tag
 * of the version it carries, or an RFC 9457 problem document — so both live here instead of being
 * repeated per route. Centralising the mapping is what makes the same failure produce the same status
 * on every content endpoint. An exception matching no arm is rethrown, so a genuine fault surfaces as
 * a 500 rather than being flattened into a client error.
 *
 * @since  2.0.0
 */
final readonly class ContentApiResponder
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
     * Render a content record as JSON, tagged with the entity tag of the version it carries.
     *
     * `ETag` and `Cache-Control` are written before `$headers` is merged, so a caller reusing either
     * name replaces the default while any other name is simply added — which is how the create route
     * attaches its `Location`. The default `no-store` matters because these documents are
     * authenticated and version sensitive: a cached copy would hand a later write a stale `ETag`.
     *
     * @param   ContentRecord                                  $record   Record serialised via `toArray()`.
     * @param   int                                            $status   Status to answer with; 201 after a create.
     * @param   array<non-empty-string, array<string>|string>  $headers  Extra headers merged over the defaults.
     *
     * @return  ResponseInterface  A JSON response carrying the record and its strong `ETag`.
     *
     * @since   2.0.0
     */
    public function record(ContentRecord $record, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($record->toArray(), $status, [
            'ETag' => (string) EntityTag::fromVersion($record->entry->version()),
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }

    /**
     * Translate a content failure into the problem response the content API returns.
     *
     * Authorization refusals become 403, a missing entry or content model 404, a failed `If-Match`
     * precondition or a version conflict detected during the write 412, and every validation failure
     * 422. Only the authorization-denied arm substitutes its own wording; the rest publish the
     * exception message as the problem detail, which is why those exceptions carry operator-safe
     * messages. An exception matching no arm is rethrown unchanged rather than mapped.
     *
     * @param   Throwable  $exception  Failure raised while a content operation ran.
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
            $exception instanceof ContentNotFound,
            $exception instanceof ContentModelNotFound => $this->problems->create(
                404,
                'Content Not Found',
                $exception->getMessage(),
                'urn:kumwe:problem:content-not-found',
                $instance,
            ),
            $exception instanceof PreconditionFailed, $exception instanceof VersionConflict => $this->problems->create(
                412,
                'Precondition Failed',
                $exception->getMessage(),
                'urn:kumwe:problem:precondition-failed',
                $instance,
            ),
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
