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
use stdClass;

final class ContentApiRequest
{
    /** @return array<string, mixed> */
    public static function json(ServerRequestInterface $request): array
    {
        try {
            $data = json_decode((string) $request->getBody(), false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }

        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        return get_object_vars($data);
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

    /** @param array<string, mixed> $body */
    public static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function data(array $body): array
    {
        $data = $body['data'] ?? new stdClass();

        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException('The data field must be a JSON object.');
        }

        return self::normalizeObject($data);
    }

    /** @param array<string, mixed> $body */
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

    /** @return array<string, mixed> */
    private static function normalizeObject(stdClass $object): array
    {
        $normalized = [];
        foreach (get_object_vars($object) as $name => $value) {
            $normalized[$name] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::normalizeObject($value);
        }
        if (is_array($value)) {
            return array_map(self::normalizeValue(...), $value);
        }

        return $value;
    }
}
