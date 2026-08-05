<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Content\Application\ContentNotFound;
use Kumwe\CMS\Content\Application\ContentModelNotFound;
use Kumwe\CMS\Content\Application\ContentRecord;
use Kumwe\CMS\Content\Domain\VersionConflict;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class ContentApiResponder
{
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /** @param array<non-empty-string, array<string>|string> $headers */
    public function record(ContentRecord $record, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($record->toArray(), $status, [
            'ETag' => (string) EntityTag::fromVersion($record->entry->version()),
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
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
