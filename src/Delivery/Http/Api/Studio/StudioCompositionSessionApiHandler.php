<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Studio;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionOperation;
use Kumwe\Idempotency\IdempotencyKey;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

/**
 * REST binding of the Blueprint composition editing the administrator composition screen performs.
 *
 * `POST /api/v1/studio/composition/sessions` opens a credential-bound Blueprint session for one provisioned
 * Content type version, and `POST /api/v1/studio/composition/{operation}` performs one of the five `artifact`
 * operations the screen's Studio shell dispatches: load, dependencies, save, publish and unpublish. The handler
 * only decodes the closed JSON body and formats the result: authorization, schemas, the Blueprint locks, keyed
 * replay, optimistic concurrency and audit all happen inside `StudioMachineCompositionGateway`, which runs the
 * browser's own Producer host. A mutation's `Idempotency-Key` header becomes that host's replay key, and its
 * `expected_revision` the revision the host fences on; refusals use the Studio authoring problem types.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionSessionApiHandler implements RequestHandlerInterface
{
    /**
     * Path prefix every Blueprint composition operation is served under.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PREFIX = '/api/v1/studio/composition/';

    /**
     * Largest accepted request body, matching Producer's canonical body bound.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_BODY_BYTES = 1048576;

    /**
     * Bind the handler to the composition gateway and the Studio refusal presenter.
     *
     * @param  StudioMachineCompositionGateway  $compositions  Machine entry to the composition screen's host.
     * @param  StudioAuthoringProblemMapper     $problems      Maps canonical Studio refusals onto problems.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMachineCompositionGateway $compositions,
        private StudioAuthoringProblemMapper $problems,
    ) {
    }

    /**
     * Serve one open or operation request.
     *
     * @param   ServerRequestInterface  $request  Bearer-authenticated, site-bound API request.
     *
     * @return  ResponseInterface  JSON result, or a Studio problem document.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $instance = (string) $request->getUri();
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $name = substr($request->getUri()->getPath(), strlen(self::PREFIX));
            $body = self::body($request);
            if ($name === 'sessions') {
                self::only($body, ['content_type_id', 'content_type_version', 'mode']);
                $mode = $body->mode ?? 'blueprint';
                $version = $body->content_type_version ?? null;
                $contentType = $body->content_type_id ?? null;
                if (
                    !in_array($mode, ['blueprint', 'read-only'], true)
                    || !is_int($version)
                    || !is_string($contentType)
                    || $contentType === ''
                ) {
                    throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
                }
                $session = $this->compositions->open($context, $contentType, $version, $mode === 'read-only');

                return new JsonResponse($session->toDocument(), 201, ['Cache-Control' => 'no-store']);
            }

            $operation = StudioMachineCompositionOperation::named($name);
            self::only($body, ['session', 'session_generation', 'argument', 'expected_revision', 'locale']);
            $argument = $body->argument ?? null;
            if (!$argument instanceof stdClass) {
                throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
            }
            $key = $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);
            $result = $this->compositions->perform(
                $context,
                $operation,
                self::string($body, 'session') ?? '',
                self::string($body, 'session_generation') ?? '',
                $argument,
                self::string($body, 'expected_revision'),
                $key instanceof IdempotencyKey ? $key->value() : null,
                self::string($body, 'locale'),
            );
            $headers = ['Cache-Control' => 'no-store'];
            if ($result->replayed) {
                $headers['Idempotency-Replayed'] = 'true';
            }

            return new JsonResponse($result->toDocument(), 200, $headers);
        } catch (StudioMachineAuthoringRefused $refused) {
            return $this->problems->problem($refused, $instance);
        } catch (InvalidArgumentException) {
            return $this->problems->problem(
                StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid'),
                $instance,
            );
        }
    }

    /**
     * Decode the closed JSON object body.
     *
     * @param   ServerRequestInterface  $request  Request whose body is decoded.
     *
     * @return  stdClass  Decoded object.
     *
     * @throws  StudioMachineAuthoringRefused  When the body is oversized, malformed or not an object.
     *
     * @since   2.0.0
     */
    private static function body(ServerRequestInterface $request): stdClass
    {
        $raw = (string) $request->getBody();
        if (strlen($raw) > self::MAXIMUM_BODY_BYTES) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }
        try {
            $body = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }
        if (!$body instanceof stdClass) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }

        return $body;
    }

    /**
     * Refuse any member outside the closed vocabulary of this request.
     *
     * @param   stdClass      $body     Decoded body.
     * @param   list<string>  $members  Admitted member names.
     *
     * @return  void
     *
     * @throws  StudioMachineAuthoringRefused  When an undeclared member is present.
     *
     * @since   2.0.0
     */
    private static function only(stdClass $body, array $members): void
    {
        if (array_diff(array_keys(get_object_vars($body)), $members) !== []) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }
    }

    /**
     * Read one optional string member.
     *
     * @param   stdClass  $body    Decoded body.
     * @param   string    $member  Member name.
     *
     * @return  ?string  The string value, or null when absent or null.
     *
     * @throws  StudioMachineAuthoringRefused  When the member is present with another type.
     *
     * @since   2.0.0
     */
    private static function string(stdClass $body, string $member): ?string
    {
        $value = $body->{$member} ?? null;
        if ($value !== null && !is_string($value)) {
            throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid');
        }

        return $value;
    }
}
