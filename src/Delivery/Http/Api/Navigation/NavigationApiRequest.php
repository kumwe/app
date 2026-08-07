<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Navigation;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Psr\Http\Message\ServerRequestInterface;

final class NavigationApiRequest
{
    /** @return array<string, mixed> */
    public static function json(ServerRequestInterface $request): array
    {
        try {
            $data = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public static function route(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The %s route identifier is missing.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    public static function string(array $body, string $name): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $name));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $body */
    public static function nullableString(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s field must be a string or null.', $name));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $body */
    public static function targetType(array $body): string
    {
        $targetType = self::string($body, 'target_type');
        if (!in_array($targetType, ['content', 'anchor', 'url'], true)) {
            throw new InvalidArgumentException('The target_type field must be content, anchor or url.');
        }

        return $targetType;
    }

    /** @param array<string, mixed> $body */
    public static function integer(array $body, string $name, int $default = 0): int
    {
        $value = $body[$name] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-negative integer.', $name));
        }

        return $value;
    }

    public static function principal(ServerRequestInterface $request): AuthenticatedPrincipal
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new InvalidArgumentException('An authenticated principal is required.');
        }

        return $principal;
    }

    public static function context(ServerRequestInterface $request): ExecutionContext
    {
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (!$context instanceof ExecutionContext) {
            throw new InvalidArgumentException('An authenticated execution context is required.');
        }

        return $context;
    }

    public static function expectedVersion(ServerRequestInterface $request, int $currentVersion): int
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);
        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($currentVersion))) {
            throw new NavigationPreconditionFailed();
        }

        return $currentVersion;
    }
}
