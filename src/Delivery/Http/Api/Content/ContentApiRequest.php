<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Psr\Http\Message\ServerRequestInterface;

final class ContentApiRequest
{
    /** @return array<string, mixed> */
    public static function json(ServerRequestInterface $request): array
    {
        try {
            $data = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        return $data;
    }

    public static function principal(ServerRequestInterface $request): AuthenticatedPrincipal
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new InvalidArgumentException('An authenticated principal is required.');
        }

        return $principal;
    }

    public static function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The content route identifier is missing.');
        }

        return $id;
    }

    public static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /** @return array<string, mixed> */
    public static function data(array $body): array
    {
        $data = $body['data'] ?? [];

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The data field must be a JSON object.');
        }

        return $data;
    }

    public static function publicationWindow(array $body): ?PublicationWindow
    {
        if (!array_key_exists('publish_at', $body) && !array_key_exists('unpublish_at', $body)) {
            return null;
        }

        $publishAt = $body['publish_at'] ?? null;
        $unpublishAt = $body['unpublish_at'] ?? null;

        if ($publishAt !== null && !is_string($publishAt)) {
            throw new InvalidArgumentException('publish_at must be an RFC 3339 timestamp or null.');
        }

        if ($unpublishAt !== null && !is_string($unpublishAt)) {
            throw new InvalidArgumentException('unpublish_at must be an RFC 3339 timestamp or null.');
        }

        return new PublicationWindow(
            $publishAt === null || $publishAt === '' ? null : new DateTimeImmutable($publishAt),
            $unpublishAt === null || $unpublishAt === '' ? null : new DateTimeImmutable($unpublishAt),
        );
    }

    public static function expectedVersion(ServerRequestInterface $request, int $currentVersion): int
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);

        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($currentVersion))) {
            throw new PreconditionFailed();
        }

        return $currentVersion;
    }
}
