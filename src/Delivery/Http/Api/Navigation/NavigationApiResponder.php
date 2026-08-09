<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationNotFound;
use Kumwe\CMS\Navigation\Application\NavigationVersionConflict;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Turns a navigation API result or failure into the HTTP response the menu routes send back.
 *
 * All four navigation handlers answer in one of two shapes — the record document tagged with the
 * entity tag of the version it carries, or an RFC 9457 problem document — so both live here rather
 * than being repeated per route, which is what makes the same failure produce the same status on
 * every endpoint. Menus and menu items share one responder because answering needs nothing of a
 * record beyond its `version` and its `toArray()`. An exception matching no arm is rethrown, so a
 * genuine fault surfaces as a 500 instead of being flattened into a client error.
 *
 * @since  2.0.0
 */
final readonly class NavigationApiResponder
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
     * Render a menu or menu item as JSON, tagged with the entity tag of the version it carries.
     *
     * `ETag` and `Cache-Control` are written before `$headers` is merged, so a caller passing either
     * name overrides the default — that is how the create routes add their `Location` alongside them.
     * The default `no-store` matters because these documents are authenticated and version sensitive:
     * a cached copy would hand a later write an `ETag` that no longer names the current revision.
     *
     * @param   MenuRecord|MenuItemRecord                      $record   Record serialised via `toArray()`.
     * @param   int                                            $status   Status to answer with; 201 after a create.
     * @param   array<non-empty-string, array<string>|string>  $headers  Extra headers merged over the defaults.
     *
     * @return  ResponseInterface  A JSON response carrying the record and its strong `ETag`.
     *
     * @since   2.0.0
     */
    public function record(object $record, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($record->toArray(), $status, [
            'ETag' => (string) EntityTag::fromVersion($record->version),
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }

    /**
     * Translate a navigation failure into the problem response the navigation API returns.
     *
     * Authorization refusals become 403, a missing menu or item 404, a stale `If-Match` precondition or
     * a version conflict detected during the write 412, and every validation failure 422. The order of
     * the arms is load-bearing: `NavigationPreconditionFailed` extends `DomainException`, so it is
     * matched ahead of the validation arm to keep a lost race a 412 rather than a 422. Only the
     * authorization-denied arm substitutes its own wording; the rest publish the exception message as
     * the problem detail, which is why those exceptions carry operator-safe messages. An exception
     * matching no arm is rethrown unchanged rather than mapped.
     *
     * @param   Throwable  $exception  Failure raised while a navigation operation ran.
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
            $exception instanceof NavigationNotFound => $this->problems->create(
                404,
                'Navigation Not Found',
                $exception->getMessage(),
                'urn:kumwe:problem:navigation-not-found',
                $instance,
            ),
            $exception instanceof NavigationPreconditionFailed,
            $exception instanceof NavigationVersionConflict => $this->problems->create(
                412,
                'Precondition Failed',
                $exception->getMessage(),
                'urn:kumwe:problem:precondition-failed',
                $instance,
            ),
            $exception instanceof InvalidArgumentException,
            $exception instanceof DomainException => $this->problems->create(
                422,
                'Unprocessable Navigation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                $instance,
            ),
            default => throw $exception,
        };
    }
}
