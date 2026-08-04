<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Navigation\Application\MenuItemRecord;
use Kumwe\CMS\Navigation\Application\MenuRecord;
use Kumwe\CMS\Navigation\Application\NavigationNotFound;
use Kumwe\CMS\Navigation\Application\NavigationVersionConflict;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class NavigationApiResponder
{
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * @param MenuRecord|MenuItemRecord $record
     * @param array<non-empty-string, array<string>|string> $headers
     */
    public function record(object $record, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse($record->toArray(), $status, [
            'ETag' => (string) EntityTag::fromVersion($record->version),
            'Cache-Control' => 'no-store',
            ...$headers,
        ]);
    }

    public function problem(Throwable $exception, string $instance): ResponseInterface
    {
        return match (true) {
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
