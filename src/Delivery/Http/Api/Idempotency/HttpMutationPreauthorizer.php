<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Identity\Application\Administration\AccessControlRepository;
use Kumwe\CMS\Identity\Application\Administration\TokenDelegationPreauthorizer;
use Kumwe\CMS\Identity\Application\Administration\TokenRotationPreauthorizer;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;
use Psr\Http\Message\ServerRequestInterface;

/** Performs exact application-policy checks before an idempotency record can be observed or reserved. */
final readonly class HttpMutationPreauthorizer
{
    public function __construct(
        private AuthorizationGateway $authorization,
        private ContentService $content,
        private AccessControlRepository $access,
        private TokenDelegationPreauthorizer $tokenDelegation,
        private TokenRotationPreauthorizer $tokenRotation,
        private ?ContentModelRepository $models = null,
    ) {
    }

    public function authorize(ServerRequestInterface $request, ExecutionContext $context): void
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        if ($method === 'POST' && $path === '/api/v1/content') {
            $this->assert($context, 'content.create', AuthorizationResource::collection('content'));
            return;
        }
        if (preg_match('#^/api/v1/content/([^/]+)(?:/(transition|restore))?$#D', $path, $match) === 1) {
            $id = rawurldecode($match[1]);
            $suffix = $match[2] ?? '';
            $action = match (true) {
                $method === 'PATCH' && $suffix === '' => 'content.update',
                $method === 'DELETE' && $suffix === '' => 'content.delete',
                $method === 'POST' && $suffix === 'restore' => 'content.restore',
                $method === 'POST' && $suffix === 'transition' => $this->transitionAction($request, $context, $id),
                default => throw new InvalidArgumentException('Unsupported idempotent content mutation.'),
            };
            $this->assert($context, $action, AuthorizationResource::item('content', $id));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/menus') {
            $this->assert($context, 'navigation.manage', AuthorizationResource::collection('menu'));
            return;
        }
        if (preg_match('#^/api/v1/menus/([^/]+)$#D', $path, $match) === 1) {
            $this->assert($context, 'navigation.manage', AuthorizationResource::item('menu', rawurldecode($match[1])));
            return;
        }
        if (preg_match('#^/api/v1/menus/([^/]+)/items$#D', $path, $match) === 1) {
            $this->assert($context, 'navigation.manage', AuthorizationResource::item('menu', rawurldecode($match[1])));
            return;
        }
        if (preg_match('#^/api/v1/menu-items/([^/]+)$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'navigation.manage',
                AuthorizationResource::item('menu_item', rawurldecode($match[1])),
            );
            return;
        }
        if ($method === 'PUT' && $path === '/api/v1/settings') {
            $this->assert(
                $context,
                'settings.manage',
                AuthorizationResource::item('site', $context->site()->identifier()),
            );
            return;
        }
        if (preg_match('#^/api/v1/extensions/([^/]+)/([^/]+)(?:/(?:activate|disable))?$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::item('extension', rawurldecode($match[1] . '/' . $match[2])),
            );
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/extension-trust-keys') {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::collection('extension_trust_key'),
            );
            return;
        }
        if (
            preg_match('#^/api/v1/extension-trust-keys/([^/]+)(?:/rotate)?$#D', $path, $match) === 1
        ) {
            $this->assert(
                $context,
                'extensions.manage',
                AuthorizationResource::item('extension_trust_key', rawurldecode($match[1])),
            );
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/users') {
            $this->assert($context, 'users.manage', AuthorizationResource::collection('user'));
            return;
        }
        if (preg_match('#^/api/v1/users/([^/]+)(?:/roles/([^/]+))?$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('user', rawurldecode($match[1])));
            if (isset($match[2])) {
                $roleId = rawurldecode($match[2]);
                $this->assert($context, 'users.manage', AuthorizationResource::item('role', $roleId));
                if ($method === 'PUT') {
                    foreach ($this->access->roleGrants($roleId) as $grant) {
                        $this->authorization->assertCanDelegate(
                            $context,
                            Capability::fromString($grant['capability']),
                            $grant['scope_type'] === 'global'
                                ? GrantScope::global()
                                : GrantScope::named(
                                    $grant['scope_type'],
                                    $grant['scope_identifier'] ?? '',
                                ),
                        );
                    }
                }
            }
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/roles') {
            $this->assert($context, 'users.manage', AuthorizationResource::collection('role'));
            return;
        }
        if (preg_match('#^/api/v1/roles/([^/]+)/grants$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('role', rawurldecode($match[1])));
            $input = $this->jsonObject($request);
            $capability = $this->requiredString($input, 'capability');
            $scopeType = strtolower(isset($input['scope_type'])
                ? $this->requiredString($input, 'scope_type')
                : 'global');
            $scopeIdentifier = $input['scope_identifier'] ?? null;
            if ($scopeIdentifier !== null && !is_string($scopeIdentifier)) {
                throw new InvalidArgumentException('The scope_identifier field must be a string or null.');
            }
            $scopeIdentifier = $scopeIdentifier === null ? null : trim($scopeIdentifier);
            $scope = $scopeType === 'global'
                ? GrantScope::global()
                : GrantScope::named($scopeType, $scopeIdentifier ?? '');
            $this->authorization->assertCanDelegate($context, Capability::fromString($capability), $scope);
            return;
        }
        if (preg_match('#^/api/v1/grants/([^/]+)$#D', $path, $match) === 1) {
            $grantId = rawurldecode($match[1]);
            $this->assert($context, 'users.manage', AuthorizationResource::item('grant', $grantId));
            $grant = $this->access->grantRecord($grantId)
                ?? throw new InvalidArgumentException('The capability grant does not exist.');
            $this->assert($context, 'users.manage', AuthorizationResource::item('role', $grant['role_id']));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/tokens') {
            $input = $this->jsonObject($request);
            $capabilities = $input['capabilities'] ?? null;
            if (!is_array($capabilities)) {
                throw new InvalidArgumentException('The capabilities field must be an array.');
            }
            $this->tokenDelegation->authorize(
                $context,
                $this->requiredString($input, 'email'),
                $capabilities,
            );
            return;
        }
        if (preg_match('#^/api/v1/tokens/([^/]+)$#D', $path, $match) === 1) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('api_token', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/v1/tokens/([^/]+)/rotate$#D', $path, $match) === 1) {
            $this->tokenRotation->authorize($context, rawurldecode($match[1]));
            return;
        }
        if ($method === 'DELETE' && preg_match('#^/api/v1/users/([^/]+)/tokens$#D', $path) === 1) {
            $this->assert(
                $context,
                'users.manage',
                AuthorizationResource::item('site', $context->site()->identifier()),
            );
            return;
        }
        if (
            $method === 'DELETE'
            && preg_match('#^/api/v1/users/([^/]+)/tokens/emergency$#D', $path, $match) === 1
        ) {
            $this->assert($context, 'users.manage', AuthorizationResource::item('user', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && $path === '/api/v1/schedules') {
            $this->assert($context, 'automation.manage', AuthorizationResource::collection('schedule'));
            return;
        }
        if (preg_match('#^/api/v1/schedules/([^/]+)$#D', $path, $match) === 1) {
            $this->assert(
                $context,
                'automation.manage',
                AuthorizationResource::item('schedule', rawurldecode($match[1])),
            );
            return;
        }
        if (preg_match('#^/api/v1/jobs/([^/]+)/(?:retry|cancel)$#D', $path, $match) === 1) {
            $this->assert($context, 'automation.manage', AuthorizationResource::item('job', rawurldecode($match[1])));
            return;
        }
        if ($method === 'POST' && in_array($path, ['/api/v1/content-types', '/api/v1/workflows'], true)) {
            $type = $path === '/api/v1/content-types' ? 'content_type' : 'workflow';
            $this->assert($context, 'content.update', AuthorizationResource::collection($type));
            return;
        }
        if ($method === 'PATCH' && preg_match('#^/api/v1/(content-types|workflows)/([^/]+)$#D', $path, $match) === 1) {
            $type = $match[1] === 'content-types' ? 'content_type' : 'workflow';
            $identifier = rawurldecode($match[2]);
            $definition = $type === 'content_type'
                ? $this->models?->contentType($context->site(), $identifier)
                : $this->models?->workflow($context->site(), $identifier);
            $resourceId = $definition?->id ?? $identifier;
            $this->assert($context, 'content.update', AuthorizationResource::item($type, $resourceId));
            return;
        }

        throw new InvalidArgumentException('The idempotent endpoint has no exact authorization policy.');
    }

    private function transitionAction(
        ServerRequestInterface $request,
        ExecutionContext $context,
        string $id,
    ): string {
        $this->assert($context, 'content.read', AuthorizationResource::item('content', $id));
        $input = $this->jsonObject($request);
        $status = $this->requiredString($input, 'status');

        return $this->content->transitionCapability($context, $id, $status)->value();
    }

    private function assert(ExecutionContext $context, string $action, AuthorizationResource $resource): void
    {
        $this->authorization->assertAllowed($context, Capability::fromString($action), $resource);
    }

    /** @return array<string, mixed> */
    private function jsonObject(ServerRequestInterface $request): array
    {
        try {
            $input = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The mutation request body is invalid JSON.', 0, $exception);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException('The mutation request body must be a JSON object.');
        }

        /** @var array<string, mixed> $input */
        return $input;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }
}
