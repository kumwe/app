<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorAccessControlHandler implements RequestHandlerInterface
{
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private AdministratorRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);
        $context = AdministratorRequest::context($request);
        $createdToken = null;
        if (strtoupper($request->getMethod()) === 'POST') {
            $form = AdministratorRequest::form($request);
            $createdToken = $this->mutate($context, $form);
            if ($createdToken === null) {
                return new RedirectResponse('/administrator/access?saved=1', 303);
            }
        }

        $context = AdministratorRequest::context($request);
        return new HtmlResponse($this->renderer->render('access-control', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'users' => $this->access->users($context),
            'roles' => $this->access->roles($context),
            'tokens' => $this->access->tokens($context),
            'available_capabilities' => $this->access->capabilities($context),
            'created_token' => $createdToken,
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, string> $form
     * @return array{token: string, token_id: string}|null
     */
    private function mutate(ExecutionContext $context, array $form): ?array
    {
        $action = AdministratorRequest::required($form, 'action');
        return match ($action) {
            'user.create' => $this->after(function () use ($context, $form): void {
                $this->access->createUser(
                    $context,
                    AdministratorRequest::required($form, 'email'),
                    AdministratorRequest::required($form, 'display_name'),
                    AdministratorRequest::required($form, 'password'),
                    UserStatus::from($form['status'] ?? 'active'),
                );
            }),
            'user.update' => $this->after(function () use ($context, $form): void {
                $this->access->updateUser(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::required($form, 'email'),
                    AdministratorRequest::required($form, 'display_name'),
                    UserStatus::from(AdministratorRequest::required($form, 'status')),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
            }),
            'role.create' => $this->after(function () use ($context, $form): void {
                $this->access->createRole(
                    $context,
                    AdministratorRequest::required($form, 'code'),
                    AdministratorRequest::required($form, 'name'),
                );
            }),
            'role.assign' => $this->after(function () use ($context, $form): void {
                $this->access->assignRole(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
            }),
            'role.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeRole(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'role_id'),
                );
            }),
            'grant.create' => $this->after(function () use ($context, $form): void {
                $scopeType = $form['scope_type'] ?? 'global';
                $scopeIdentifier = trim($form['scope_identifier'] ?? '');
                $this->access->grant(
                    $context,
                    AdministratorRequest::required($form, 'role_id'),
                    AdministratorRequest::required($form, 'capability'),
                    $scopeType,
                    $scopeIdentifier === '' ? null : $scopeIdentifier,
                );
            }),
            'grant.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeGrant($context, AdministratorRequest::required($form, 'grant_id'));
            }),
            'token.create' => $this->createToken($form, $context),
            'token.revoke' => $this->after(function () use ($context, $form): void {
                $this->access->revokeToken($context, AdministratorRequest::required($form, 'token_id'));
            }),
            'token.rotate' => $this->identities->rotateAccessToken(
                $context,
                AdministratorRequest::required($form, 'token_id'),
                AdministratorRequest::required($form, 'token_name'),
                trim($form['expires_at'] ?? '') === ''
                    ? null
                    : new DateTimeImmutable($form['expires_at']),
            ),
            'token.emergency_revoke' => $this->after(function () use ($context, $form): void {
                $this->access->emergencyRevokeAllSubjectTokens(
                    $context,
                    AdministratorRequest::required($form, 'user_id'),
                    AdministratorRequest::required($form, 'reason'),
                );
            }),
            default => throw new InvalidArgumentException('The access-control action is not supported.'),
        };
    }

    /** @param callable(): void $operation */
    private function after(callable $operation): null
    {
        $operation();

        return null;
    }

    /**
     * @param array<string, string> $form
     * @return array{token: string, token_id: string}
     */
    private function createToken(array $form, ExecutionContext $context): array
    {
        $capabilities = array_values(array_filter(array_map(
            'trim',
            explode(',', AdministratorRequest::required($form, 'token_capabilities')),
        ), static fn (string $capability): bool => $capability !== ''));
        $expiresAt = trim($form['expires_at'] ?? '');

        return $this->identities->issueAccessToken(
            $context,
            AdministratorRequest::required($form, 'token_email'),
            AdministratorRequest::required($form, 'token_name'),
            $capabilities,
            $expiresAt === '' ? null : new DateTimeImmutable($expiresAt),
            $form['audience'] ?? 'kumwe-http',
            $form['purpose'] ?? 'api',
        );
    }
}
