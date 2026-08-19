<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Identity;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Domain\UserStatus;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Single PSR-15 handler behind every user, role, capability-grant and API-token route of `/api/v1`.
 *
 * Fifteen routes share one handler because they share one job: resolve the actor's execution context,
 * hand the request to `AccessControlService` or `AdministratorIdentityGateway`, and turn what comes
 * back — a listing, a new identifier, a revocation count, nothing at all — into a JSON or empty
 * response. Policy, delegation limits and auditing stay in those collaborators; the wire contract is
 * what lives here. `operation()` is the routing table, matched on method and path rather than read back
 * from the route name, so the handler depends on nothing but the request. Only
 * `InvalidArgumentException` is translated, into a 422 problem document, because that is the exception
 * both collaborators raise for unusable input; every other failure — an authorization refusal above
 * all — is re-thrown unchanged, since a refusal answered as a validation error would tell a caller its
 * request was malformed rather than forbidden. Every response carries `no-store`: these documents
 * describe credentials and access, and a cached copy of one outlives the grant it reports.
 *
 * @since  2.0.0
 */
final readonly class AccessControlApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the handler to the two identity collaborators and to the problem-document factory.
     *
     * Token issue and rotation go through the gateway rather than the service because they are the only
     * operations that mint a secret, and the gateway is what enforces the delegation and quota rules
     * that minting one requires.
     *
     * @param  AccessControlService           $access      Owns users, roles, grants and token revocation.
     * @param  AdministratorIdentityGateway   $identities  Mints and rotates bearer tokens, secret included.
     * @param  ProblemDetailsResponseFactory  $problems    Renders the 422 answer for unusable input.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AccessControlService $access,
        private AdministratorIdentityGateway $identities,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Dispatch the request to the identity operation its method and path name, and render the outcome.
     *
     * The three listing arms are inline because each is one service call and a JSON body; every other
     * arm delegates to a private method that validates the request before the collaborator sees it. A
     * method-and-path pair no arm recognises is refused as 422 rather than 404, since the router only
     * mounts paths this handler defines, so reaching the default arm means the route table and this one
     * have drifted. The role listing answers with the capability catalogue beside the roles, so an
     * administration screen can populate a grant form from one round trip.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request, already past the capability check.
     *
     * @return  ResponseInterface  The operation's JSON or 204 response, or a 422 problem document when the
     *          route, body or route parameter was unusable.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not perform the
     *          operation; it is re-thrown deliberately so it is answered as a refusal, not as a 422.
     *
     * @since   2.0.0
     */
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
                'tokens.rotate' => $this->rotateToken($request),
                'tokens.emergency_revoke' => $this->revokeSubjectTokens($request),
                'tokens.emergency_revoke_all' => $this->emergencyRevokeAllSubjectTokens($request),
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

    /**
     * Resolve the request's method and path into the operation token the dispatcher matches on.
     *
     * The same path serves several operations, so both halves of the pair are matched together: a `PUT`
     * and a `DELETE` on `/api/v1/users/{id}/roles/{roleId}` are assignment and revocation, and the two
     * token sweeps differ only by an `/emergency` suffix. A method the API does not define on an
     * otherwise known path falls to the default arm and is refused, never treated as a neighbour.
     *
     * @param   ServerRequestInterface  $request  Request whose method and path name the operation.
     *
     * @return  string  Dotted operation token, such as `users.update` or `tokens.rotate`.
     *
     * @throws  InvalidArgumentException  When no identity operation matches that method and path pair.
     *
     * @since   2.0.0
     */
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
            preg_match('#^/api/v1/tokens/[^/]+/rotate$#D', $path) === 1 && $method === 'POST' => 'tokens.rotate',
            preg_match('#^/api/v1/users/[^/]+/tokens$#D', $path) === 1 && $method === 'DELETE' =>
                'tokens.emergency_revoke',
            preg_match('#^/api/v1/users/[^/]+/tokens/emergency$#D', $path) === 1 && $method === 'DELETE' =>
                'tokens.emergency_revoke_all',
            default => throw new InvalidArgumentException('The identity operation is not supported.'),
        };
    }

    /**
     * Create a user from the request body and answer with where to find it.
     *
     * `status` is the one optional field, defaulting to `active`, so the ordinary case is a body of
     * address, name and password. The plaintext password only travels as far as the service, which
     * hashes it before anything is written, and never comes back in the response.
     *
     * @param   ServerRequestInterface  $request  Request whose JSON body describes the user to create.
     *
     * @return  ResponseInterface  201 carrying the new UUID under `id`, with a `Location` for the user.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, `email`, `display_name` or
     *          `password` is absent or blank, or the service rejects the address or
     *          the display name.
     * @throws  \ValueError  When `status` is present but spells no known lifecycle status; the enum is
     *          read before the service is called, so this escapes the 422 mapping.
     *
     * @since   2.0.0
     */
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

    /**
     * Replace a user's address, name and status under the version the caller read.
     *
     * This is a whole-record write despite the `PATCH` method: every field is mandatory, so a client
     * that sends only what changed would blank the rest. `version` carries the optimistic-concurrency
     * check instead of an `If-Match` header on this route, and the service re-tests it inside the write
     * transaction, so an edit made against a stale read is refused rather than applied.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the user and whose
     *          JSON body carries the replacement fields.
     *
     * @return  ResponseInterface  204 with `no-store`; the caller re-reads the user for its new version.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing, the body is not a JSON
     *          object, a field is absent or blank, `version` is not a positive
     *          integer, or the service refuses the edit as stale, as a forbidden
     *          status transition, or as one that would lock the actor out.
     * @throws  \ValueError  When `status` spells no known lifecycle status; the enum is read before the
     *          service is called, so this escapes the 422 mapping.
     *
     * @since   2.0.0
     */
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

    /**
     * Create an empty role from the request body.
     *
     * A role arrives with no capabilities; `grants.create` adds them one at a time, so that each grant
     * passes the delegation check on its own rather than riding in on the creation.
     *
     * @param   ServerRequestInterface  $request  Request whose JSON body carries the role's `code` and `name`.
     *
     * @return  ResponseInterface  201 carrying the new UUID under `id`; no `Location`, since roles have no
     *          resource route to point at.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, `code` or `name` is absent or
     *          blank, or the service refuses the code as an unstable identifier.
     *
     * @since   2.0.0
     */
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

    /**
     * Give the addressed user the addressed role.
     *
     * Both identifiers come from the path and there is no body, which is what makes the route a `PUT`:
     * repeating it leaves the same assignment in place. Whether the actor may hand over everything the
     * role grants is settled twice — once by the idempotency middleware's preauthorization before this
     * runs, and again by the service inside the write transaction, with the role locked, so a capability
     * added to the role in between cannot ride in on the assignment.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` and `roleId` route attributes name the
     *          user and the role.
     *
     * @return  ResponseInterface  204 with `no-store`.
     *
     * @throws  InvalidArgumentException  When either route attribute is missing, or the service finds no
     *          role under that identifier.
     *
     * @since   2.0.0
     */
    private function assignRole(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->assignRole(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->route($request, 'roleId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Take the addressed role away from the addressed user.
     *
     * The service refuses an actor stripping its own administrator role, so this route cannot be used
     * to lock the installation out of its own access control.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` and `roleId` route attributes name the
     *          user and the role.
     *
     * @return  ResponseInterface  204 with `no-store`.
     *
     * @throws  InvalidArgumentException  When either route attribute is missing, the user or role is
     *          unknown, or the actor is removing its own administrator role.
     *
     * @since   2.0.0
     */
    private function revokeRole(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeRole(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->route($request, 'roleId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Grant one capability to the addressed role, globally or within a named scope.
     *
     * `scope_type` defaults to `global`, and `scope_identifier` says which instance of a narrower scope
     * the grant applies in — it stays null for a global grant. One capability per request is deliberate:
     * each grant is checked against what the actor may itself delegate, so a wider one cannot be smuggled
     * in beside a narrower one.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the role and whose
     *          JSON body describes the capability and its scope.
     *
     * @return  ResponseInterface  201 carrying the new grant's UUID under `id`, which is what the revoke
     *          route addresses it by.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing, the body is not a JSON
     *          object, `capability` is absent or blank, `scope_type` or
     *          `scope_identifier` is present but not a string, or the service
     *          rejects the capability or the scope pairing.
     *
     * @since   2.0.0
     */
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

    /**
     * Remove one capability grant from the role that holds it.
     *
     * The grant is addressed by its own identifier rather than by role and capability, so revoking the
     * `global` grant of a capability cannot accidentally take a scoped grant of the same capability
     * with it.
     *
     * @param   ServerRequestInterface  $request  Request whose `grantId` route attribute names the grant.
     *
     * @return  ResponseInterface  204 with `no-store`.
     *
     * @throws  InvalidArgumentException  When the route attribute is missing, or no grant carries that
     *          identifier.
     *
     * @since   2.0.0
     */
    private function revokeGrant(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeGrant(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'grantId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Mint a bearer token for a subject and return its secret, the one time it is ever shown.
     *
     * The capability list is checked here rather than left to the gateway, because a body that is not a
     * JSON list of strings is a wire error and belongs in a 422; what the actor may actually delegate is
     * the gateway's decision. `expires_at`, `audience` and `purpose` are optional, falling back to the
     * gateway's default lifetime, `kumwe-http` and `api`. The body is flagged `secret_returned: true`,
     * which is what distinguishes this answer from the copy `SecretOnceIdempotencyMiddleware` keeps for
     * replay — that one has the secret stripped and the flag set to false.
     *
     * @param   ServerRequestInterface  $request  Request whose JSON body describes the token to mint.
     *
     * @return  ResponseInterface  201 carrying the plaintext secret under `token`, the stored record's
     *          UUID under `token_id`, and `secret_returned` set to true.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, `capabilities` is absent or
     *          is not a list of strings, `email` or `name` is absent or blank, or
     *          the gateway refuses the name, the expiry or the subject's quota.
     * @throws  \DateMalformedStringException  When `expires_at` is present but spells no parseable instant.
     *
     * @since   2.0.0
     */
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
            $this->optionalString($body, 'audience') ?? 'kumwe-http',
            $this->optionalString($body, 'purpose') ?? 'api',
        );

        $created['secret_returned'] = true;

        return new JsonResponse($created, 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Replace a live token with a fresh secret carrying the same authority.
     *
     * Only the label and the expiry are supplied: subject, capabilities, audience and purpose are copied
     * from the token being replaced, so a rotation can never quietly widen what the credential may do.
     * The old token is revoked in the same transaction the replacement is written in, so a caller that
     * loses this response has lost both and must issue a token afresh.
     *
     * @param   ServerRequestInterface  $request  Request whose `tokenId` route attribute names the token
     *          being replaced and whose JSON body carries the replacement's `name`.
     *
     * @return  ResponseInterface  201 carrying the replacement's plaintext secret under `token`, its new
     *          UUID under `token_id`, and `secret_returned` set to true.
     *
     * @throws  InvalidArgumentException  When the route attribute is missing, the body is not a JSON
     *          object, `name` is absent or blank, or the gateway finds the token
     *          unknown, already dead or belonging to another site.
     * @throws  \DateMalformedStringException  When `expires_at` is present but spells no parseable instant.
     *
     * @since   2.0.0
     */
    private function rotateToken(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $expiresAt = $this->optionalString($body, 'expires_at');
        $created = $this->identities->rotateAccessToken(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'tokenId'),
            $this->string($body, 'name'),
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
        );
        $created['secret_returned'] = true;
        return new JsonResponse($created, 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Revoke every token the addressed subject holds for the acting site.
     *
     * The contained sweep: tokens the subject holds in other sites keep working, which is why it is
     * authorized against the site rather than against the user. `reason` is mandatory because it is
     * stored on each revoked token as well as in the audit entry, and that pairing is what makes an
     * incident reconstructable afterwards.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the subject and
     *          whose JSON body carries the operator's `reason`.
     *
     * @return  ResponseInterface  200 carrying the number of live tokens revoked under `revoked_tokens`;
     *          zero means the subject held none for this site, not that the sweep failed.
     *
     * @throws  InvalidArgumentException  When the route attribute is missing, the body is not a JSON
     *          object, `reason` is absent, blank or longer than 500 characters, or
     *          the subject does not exist.
     *
     * @since   2.0.0
     */
    private function revokeSubjectTokens(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $count = $this->access->revokeSubjectTokens(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->string($body, 'reason'),
        );
        return new JsonResponse(['revoked_tokens' => $count], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Revoke every token the addressed subject holds anywhere, as a break-glass measure.
     *
     * The unbounded counterpart of the site-scoped sweep, reached by the `/emergency` suffix and
     * authorized against the user rather than against one site. The service also advances the subject's
     * security epoch, so even a credential the sweep somehow missed stops verifying.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the subject and
     *          whose JSON body carries the operator's `reason`.
     *
     * @return  ResponseInterface  200 carrying the number of live tokens revoked under `revoked_tokens`;
     *          zero means the subject held none at all.
     *
     * @throws  InvalidArgumentException  When the route attribute is missing, the body is not a JSON
     *          object, `reason` is absent, blank or longer than 500 characters, or
     *          the subject does not exist.
     *
     * @since   2.0.0
     */
    private function emergencyRevokeAllSubjectTokens(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $count = $this->access->emergencyRevokeAllSubjectTokens(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'id'),
            $this->string($body, 'reason'),
        );
        return new JsonResponse(['revoked_tokens' => $count], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Revoke one named token, leaving the subject's other credentials alone.
     *
     * The ordinary way a single leaked or retired credential is withdrawn; the two sweeps exist for the
     * case where the subject itself is compromised. The service refuses a token belonging to another
     * site, and refuses one that is already dead rather than reporting success a second time.
     *
     * @param   ServerRequestInterface  $request  Request whose `tokenId` route attribute names the token.
     *
     * @return  ResponseInterface  204 with `no-store`.
     *
     * @throws  InvalidArgumentException  When the route attribute is missing, or the token is unknown,
     *          already revoked, or outside the acting site.
     *
     * @since   2.0.0
     */
    private function revokeToken(ServerRequestInterface $request): ResponseInterface
    {
        $this->access->revokeToken(
            ApiExecutionContext::fromRequest($request),
            $this->route($request, 'tokenId'),
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Decode the request body as a JSON object.
     *
     * A top-level list is refused alongside scalars and `null`, because every identity body is keyed and
     * accepting a list would only move the type failure into a field lookup further down. Decoding is
     * depth limited, so a deeply nested body is rejected rather than exhausting the stack.
     *
     * @param   ServerRequestInterface  $request  Request whose body is read in full.
     *
     * @return  array<string, mixed>  The decoded object, keyed by wire field name.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON, nests deeper than 32 levels, or
     *          decodes to anything other than a JSON object.
     *
     * @since   2.0.0
     */
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

    /**
     * Read one mandatory string field out of a decoded body.
     *
     * Absent, wrongly typed and whitespace-only are one failure on purpose: an email or a revocation
     * reason that trims to nothing tells the service as little as one that was never sent.
     *
     * @param   array<string, mixed>  $body   Decoded request body.
     * @param   string                $field  Wire name of the field, repeated verbatim in the failure message.
     *
     * @return  string  The field's value with surrounding whitespace removed, guaranteed non-empty.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not a string, or is empty once trimmed.
     *
     * @since   2.0.0
     */
    private function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /**
     * Read an optional string field, folding both absence and emptiness into null.
     *
     * The empty string deliberately becomes null rather than being refused, so a client clears
     * `scope_identifier` or leaves `status`, `expires_at`, `audience` and `purpose` to their defaults by
     * sending `""` as readily as by omitting the member. Each caller supplies its own fallback, so null
     * here means "the client said nothing", never a decision.
     *
     * @param   array<string, mixed>  $body   Decoded request body.
     * @param   string                $field  Wire name of the field, repeated verbatim in the failure message.
     *
     * @return  ?string  The trimmed value, or null when the field is absent, null, or the empty string.
     *
     * @throws  InvalidArgumentException  When the field is present and non-empty but is not a string.
     *
     * @since   2.0.0
     */
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

    /**
     * Read a mandatory positive integer field, used for the optimistic-concurrency version.
     *
     * The value must have decoded as a JSON number, so `"3"` is refused rather than coerced: a version
     * quietly built from a string would let a client defeat the stale-write check by sending the wrong
     * type. Zero and negatives are refused too, since a version below one names no stored revision.
     *
     * @param   array<string, mixed>  $body   Decoded request body.
     * @param   string                $field  Wire name of the field, repeated verbatim in the failure message.
     *
     * @return  int  The supplied value, one or greater.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not an integer, or is below one.
     *
     * @since   2.0.0
     */
    private function positiveInteger(array $body, string $field): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('The %s field must be a positive integer.', $field));
        }

        return $value;
    }

    /**
     * Read an identifier the router captured from the request path.
     *
     * The identity routes name their segments differently — `id` for a user, `roleId`, `grantId`,
     * `tokenId` — so the attribute to read is a parameter rather than a constant. Only presence is
     * checked here; whether the value is a canonical UUID is the service's decision, so a path
     * identifier is held to the same rule as one from a body.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     * @param   string                  $field    Route attribute to read, quoted in the failure message.
     *
     * @return  string  The captured value; non-empty, but not otherwise validated here.
     *
     * @throws  InvalidArgumentException  When the attribute is absent, empty, or not a string, which means
     *          the handler was mounted on a route without that segment.
     *
     * @since   2.0.0
     */
    private function route(ServerRequestInterface $request, string $field): string
    {
        $value = $request->getAttribute($field);
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The %s route parameter is missing.', $field));
        }

        return $value;
    }
}
