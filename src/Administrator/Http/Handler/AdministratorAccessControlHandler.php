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

/**
 * Serves the administrator access-control screen and applies the identity changes it posts back.
 *
 * Users, roles, capability grants and API tokens are managed from one screen behind one
 * `users.manage` capability, because deciding who may do what means seeing all four together. `GET`
 * renders the current state; `POST` dispatches on the form's `action` field and then redirects, so a
 * refresh cannot replay a change. Token issue and rotation are the deliberate exception: they render
 * instead of redirecting, because the plaintext secret is shown once and is never recoverable.
 *
 * @since  2.0.0
 */
final readonly class AdministratorAccessControlHandler implements RequestHandlerInterface
{
    /**
     * Wire the screen to the services that read and change administrator identities.
     *
     * @param  AccessControlService          $access      Reads and mutates users, roles, grants and tokens.
     * @param  AdministratorIdentityGateway  $identities  Issues and rotates tokens, the two secret-bearing acts.
     * @param  AdministratorRenderer         $renderer    Renders the `access-control` template.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private AdministratorRenderer $renderer,
    ) {
    }

    /**
     * Render the access-control screen, first applying whatever change a `POST` carries.
     *
     * A change that produces no secret redirects to `?saved=1` so the browser cannot resubmit it.
     * Token issue and rotation fall through to the render instead, because the plaintext token is
     * handed to the operator exactly once. The response is marked `no-store` for that reason as much
     * as for the CSRF token it carries.
     *
     * @param   ServerRequestInterface  $request  Administrator request, already authenticated and CSRF-checked.
     *
     * @return  ResponseInterface  The rendered screen, or a 303 redirect when there is no secret to show.
     *
     * @throws  InvalidArgumentException  When a required field is missing or the action is not supported.
     * @throws  \DateMalformedStringException  When a token expiry field is not a readable date and time.
     *
     * @since   2.0.0
     */
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
     * Apply the single access-control operation named by the form's `action` field.
     *
     * Each branch reads only its own fields, so one action can never pick up a value meant for
     * another. The return value is what tells the caller apart: only the token branches yield
     * something, and everything else goes through `after()` to say "done, redirect".
     *
     * @param   ExecutionContext       $context  Actor and site the change is authorised and audited against.
     * @param   array<string, string>  $form     Flattened form as returned by `AdministratorRequest::form()`.
     *
     * @return  array{token: string, token_id: string}|null  Secret and id, or null when none was issued.
     *
     * @throws  InvalidArgumentException  When a required field is missing or `action` names no known operation.
     * @throws  \DateMalformedStringException  When the rotation expiry field is not a readable date and time.
     *
     * @since   2.0.0
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

    /**
     * Run a change that yields nothing and report the absence of a secret to show.
     *
     * The helper exists so that every non-token arm of the `match` above is an expression of the same
     * type; returning `null` rather than `void` is what lets a statement-shaped operation sit there.
     *
     * @param   callable(): void  $operation  The access-control change to perform.
     *
     * @return  null  Always null, telling the caller to redirect rather than render.
     *
     * @since   2.0.0
     */
    private function after(callable $operation): null
    {
        $operation();

        return null;
    }

    /**
     * Issue a new API token for a subject and return the only copy of its secret.
     *
     * Capabilities arrive as one comma-separated field because the form posts a multi-select; blank
     * entries are dropped so a trailing comma cannot request an unnamed capability. Audience and
     * purpose fall back to the HTTP API defaults when the form leaves them out.
     *
     * @param   array<string, string>  $form     Flattened form carrying the token fields.
     * @param   ExecutionContext       $context  Actor and site the issue is authorised and audited against.
     *
     * @return  array{token: string, token_id: string}  Plaintext secret, shown once, and the stored id.
     *
     * @throws  InvalidArgumentException  When a required token field is missing or blank.
     * @throws  \DateMalformedStringException  When the expiry field is not a readable date and time.
     *
     * @since   2.0.0
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
