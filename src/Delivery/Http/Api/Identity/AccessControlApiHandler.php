<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Identity;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class AccessControlApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $operation = $this->operation($request);

            return match ($operation) {
                'users.list' => new JsonResponse(
                    ['items' => $this->access->users(ApiExecutionContext::fromRequest($request))],
                    200,
                    ['Cache-Control' => 'no-store'],
                ),
                'users.create' => $this->createUser($request),
                'users.update' => $this->updateUser($request),
                'roles.list' => new JsonResponse([
                    'items' => $this->access->roles(ApiExecutionContext::fromRequest($request)),
                    'capabilities' => $this->access->capabilities(ApiExecutionContext::fromRequest($request)),
                ], 200, ['Cache-Control' => 'no-store']),
                'roles.create' => $this->createRole($request),
                'roles.assign' => $this->assignRole($request),
                'roles.revoke' => $this->revokeRole($request),
                'grants.create' => $this->createGrant($request),
                'grants.revoke' => $this->revokeGrant($request),
                'tokens.create' => $this->createToken($request),
                'tokens.list' => new JsonResponse(
                    ['items' => $this->access->tokens(ApiExecutionContext::fromRequest($request))],
                    200,
                    ['Cache-Control' => 'no-store'],
                ),
                'tokens.revoke' => $this->revokeToken($request),
                default => throw new InvalidArgumentException('The identity operation is not supported.'),
            };
        } catch (Throwable $exception) {
            if (!$exception instanceof InvalidArgumentException) {
                throw $exception;
            }

            return $this->problems->create(
                422,
                'Unprocessable Identity Operation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }

    private function operation(ServerRequestInterface $request): string
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        return match (true) {
            $path === '/api/v1/users' && $method === 'GET' => 'users.list',
            $path === '/api/v1/users' && $method === 'POST' => 'users.create',
            preg_match('#^/api/v1/users/[^/]+$#D', $path) === 1 && $method === 'PATCH' => 'users.update',
            $path === '/api/v1/roles' && $method === 'GET' => 'roles.list',
            $path === '/api/v1/roles' && $method === 'POST' => 'roles.create',
            preg_match('#^/api/v1/users/[^/]+/roles/[^/]+$#D', $path) === 1 && $method === 'PUT' => 'roles.assign',
            preg_match('#^/api/v1/users/[^/]+/roles/[^/]+$#D', $path) === 1 && $method === 'DELETE' => 'roles.revoke',
            preg_match('#^/api/v1/roles/[^/]+/grants$#D', $path) === 1 && $method === 'POST' => 'grants.create',
            preg_match('#^/api/v1/grants/[^/]+$#D', $path) === 1 && $method === 'DELETE' => 'grants.revoke',
            $path === '/api/v1/tokens' && $method === 'POST' => 'tokens.create',
            $path === '/api/v1/tokens' && $method === 'GET' => 'tokens.list',
            preg_match('#^/api/v1/tokens/[^/]+$#D', $path) === 1 && $method === 'DELETE' => 'tokens.revoke',
            default => throw new InvalidArgumentException('The identity operation is not supported.'),
        };
    }

    private function createUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $id = $this->access->createUser(
            ApiExecutionContext::fromRequest($request),
            $this->string($body, 'email'),
            $this->string($body, 'display_name'),
            $this->string($body, 'password'),
            UserStatus::from($this->optionalString($body, 'status') ?? 'active'),
        );

        return new JsonResponse(['id' => $id], 201, [
            'Location' => '/api/v1/users/' . $id,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function updateUser(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $this->access->updateUser(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->string($body, 'email'),
            $this->string($body, 'display_name'),
            UserStatus::from($this->string($body, 'status')),
            $this->positiveInteger($body, 'version'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function createRole(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $id = $this->access->createRole(
            ApiExecutionContext::fromRequest($request),
            $this->string($body, 'code'),
            $this->string($body, 'name'),
        );

        return new JsonResponse(['id' => $id], 201, ['Cache-Control' => 'no-store']);
    }

    private function assignRole(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->assignRole(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->route($request, 'roleId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function revokeRole(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeRole(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->route($request, 'roleId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function createGrant(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $id = $this->access->grant(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->string($body, 'capability'),
            $this->optionalString($body, 'scope_type') ?? 'global',
            $this->optionalString($body, 'scope_identifier'),
        );

        return new JsonResponse(['id' => $id], 201, ['Cache-Control' => 'no-store']);
    }

    private function revokeGrant(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeGrant(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'grantId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function createToken(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $capabilities = $body['capabilities'] ?? null;
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new InvalidArgumentException('Token capabilities must be a JSON list.');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Token capabilities must contain strings.');
            }
        }
        /** @var list<string> $capabilities */
        $expiresAt = $this->optionalString($body, 'expires_at');
        $created = $this->identities->issueAccessToken(
            ApiExecutionContext::fromRequest($request),
            $this->string($body, 'email'),
            $this->string($body, 'name'),
            $capabilities,
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
        );

        return new JsonResponse($created, 201, ['Cache-Control' => 'no-store']);
    }

    private function revokeToken(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeToken(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'tokenId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        try {
            $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @param array<string, mixed> $body */
    private function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s field must be a string or null.', $field));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $body */
    private function positiveInteger(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a positive integer.', $field));
        }

        return $value;
    }

    private function route(ServerRequestInterface $request, string $field): string
    {
        $value = $request->getAttribute($field);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The %s route parameter is missing.', $field));
        }

        return $value;
    }
}
