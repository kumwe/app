<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Extension;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the extension trust-key REST resource: which signing keys are trusted, and how they change.
 *
 * A trust key decides whose release signatures an installation will accept, so the four mutations here
 * are the sharp end of extension supply-chain policy. The route shape selects the operation rather than a
 * body field: a `POST` to the collection adds a key, a `POST` to a named key rotates it, and a `DELETE`
 * finalizes that rotation — unless the body carries `emergency: true`, which takes the break-glass path
 * that revokes the key outright and quarantines every release it signed. Any other combination is
 * refused rather than guessed at. Every non-error answer carries `Cache-Control: no-store`, because a
 * cached trust listing would outlive the revocation it is meant to reflect. Serialising these against
 * concurrent lifecycle work belongs to `TrustLifecycleMiddleware` and replay protection to the
 * idempotency middleware; what this handler owns is body validation and turning a rejected operation
 * into a 422 problem document.
 *
 * @since  2.0.0
 */
final readonly class TrustStoreApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the trust store and the factory that renders its refusals.
     *
     * @param  TrustStore                     $trust     Service owning trust-key policy, storage and audit.
     * @param  ProblemDetailsResponseFactory  $problems  Builds the `application/problem+json` body sent back.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustStore $trust, private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * List the trusted keys, or apply the addition, rotation or revocation the route names.
     *
     * A `GET` answers the listing and reads no body; every other verb decodes a JSON object first. The
     * presence of the `keyId` route attribute then separates a collection operation from one against a
     * single key: `POST` without it adds and answers an empty 201, `POST` with it rotates and answers an
     * empty 204, and `DELETE` either emergency-revokes — answering 200 with the identifiers it
     * quarantined — or finalizes a rotation and answers an empty 204. An omitted `vendor_namespace` or
     * `extension_pattern` widens to `*`. Every validation failure becomes a 422, including a request whose
     * execution context does not match its authenticated principal; a refusal by policy is deliberately
     * left to the pipeline's problem-details boundary instead.
     *
     * @param   ServerRequestInterface  $request  Request whose method and optional `keyId` route attribute
     *          select the operation and whose JSON body carries its arguments.
     *
     * @return  ResponseInterface  The key listing or quarantine result as JSON, an empty 201 or 204 after a
     *          successful mutation, or a 422 problem document explaining the refusal.
     *
     * @throws  \Kumwe\App\Application\Authorization\AuthorizationDenied  When the actor may not manage
     *          extensions, which this handler passes on rather than rendering itself.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $keyId = $request->getAttribute('keyId');
            if ($method === 'GET') {
                return new JsonResponse(['items' => $this->trust->keys($actor)], 200, ['Cache-Control' => 'no-store']);
            }
            $body = $this->json($request);
            if ($method === 'POST' && !is_string($keyId)) {
                $this->trust->add(
                    $actor,
                    $this->string($body, 'key_id'),
                    $this->string($body, 'public_key_base64'),
                    $this->optional($body, 'vendor_namespace') ?? '*',
                    $this->optional($body, 'extension_pattern') ?? '*',
                    $this->date($body, 'expires_at'),
                );
                return new EmptyResponse(201, ['Cache-Control' => 'no-store']);
            }
            if ($method === 'POST' && is_string($keyId)) {
                $this->trust->rotate(
                    $actor,
                    $keyId,
                    $this->string($body, 'new_key_id'),
                    $this->string($body, 'public_key_base64'),
                    $this->optional($body, 'vendor_namespace') ?? '*',
                    $this->optional($body, 'extension_pattern') ?? '*',
                    $this->date($body, 'expires_at'),
                );
                return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
            }
            if ($method === 'DELETE' && is_string($keyId)) {
                if (($body['emergency'] ?? false) === true) {
                    return new JsonResponse(['quarantined' => $this->trust->emergencyRevoke(
                        $actor,
                        $keyId,
                        $this->string($body, 'reason'),
                    )], 200, ['Cache-Control' => 'no-store']);
                }
                $this->trust->finalizeRotation($actor, $keyId, $this->string($body, 'reason'));
                return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
            }
            throw new InvalidArgumentException('The trust-store operation is not supported.');
        } catch (InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Trust-Store Operation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }

    /**
     * Decode the request body as the JSON object the mutation arguments are read from.
     *
     * A JSON array is refused as firmly as malformed JSON, since every operation here reads named fields.
     *
     * @param   ServerRequestInterface  $request  Mutation request whose body carries the operation arguments.
     *
     * @return  array<string, mixed>  The decoded object's members, keyed by field name.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON within 16 levels of nesting, or
     *          decodes to something other than a JSON object.
     *
     * @since   2.0.0
     */
    private function json(ServerRequestInterface $request): array
    {
        try {
            $value = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The request body must be valid JSON.', 0, $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Read a body field the operation requires as a non-empty string.
     *
     * @param   array<string, mixed>  $body  Decoded request body.
     * @param   string                $name  Field to read, named back to the operator in the refusal.
     *
     * @return  string  The field's value with surrounding whitespace removed.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not a string, or is blank once trimmed.
     *
     * @since   2.0.0
     */
    private function string(array $body, string $name): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $name));
        }
        return trim($value);
    }

    /**
     * Read a body field the operation may leave out.
     *
     * A blank or non-string value is reported as absent rather than refused, so the caller's `*` default
     * applies to a field sent empty exactly as it does to one omitted.
     *
     * @param   array<string, mixed>  $body  Decoded request body.
     * @param   string                $name  Field to read.
     *
     * @return  ?string  The trimmed value, or null when the field is absent, not a string, or blank.
     *
     * @since   2.0.0
     */
    private function optional(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Read a body field the operation requires as a parseable timestamp.
     *
     * The required-field check sits inside the same guarded block as the parse, so an absent value and an
     * unparseable one are both reported as an invalid timestamp; the original refusal is retained as the
     * previous exception.
     *
     * @param   array<string, mixed>  $body  Decoded request body.
     * @param   string                $name  Field to read, named back to the operator in the refusal.
     *
     * @return  DateTimeImmutable  The parsed instant, falling back to the default timezone when the value
     *          declares none.
     *
     * @throws  InvalidArgumentException  When the field is absent or blank, or its value is not a timestamp
     *          `DateTimeImmutable` can parse.
     *
     * @since   2.0.0
     */
    private function date(array $body, string $name): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($this->string($body, $name));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(
                sprintf('The %s field must be a valid timestamp.', $name),
                0,
                $exception,
            );
        }
    }
}
