<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Plan;

use JsonException;
use Kumwe\App\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\App\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValueError;

/**
 * Serves `POST /api/v1/plans`, the preview that describes a change without carrying a way to apply it.
 *
 * The route exists so an automation client — an agent, a review workflow, an integration — can state
 * what it intends before a human approves anything. It reads no domain state and writes none: the
 * answer is assembled entirely from the request, always says `mode: plan_only` and
 * `apply_supported: false`, and only the reviews named by `SafePlanOperation` are accepted, so an
 * unexposed action cannot be smuggled through the `operation` member. Applying a change still goes
 * through the content, navigation or identity endpoint that owns it, under that endpoint's own
 * capability and precondition rules.
 *
 * @since  2.0.0
 */
final readonly class PlanPreviewHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the factory that mints plans and the factory that renders its refusals.
     *
     * @param  SafePlanFactory                $plans     Mints the plan with its identifier and validity window.
     * @param  ProblemDetailsResponseFactory  $problems  Builds the 400 problem documents this route answers with.
     *
     * @since  2.0.0
     */
    public function __construct(
        private SafePlanFactory $plans,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Answer with a plan describing the requested review, or a problem document refusing the request.
     *
     * The idempotency attribute is checked before anything else: its absence means the route was
     * mounted without `RequireIdempotencyKeyMiddleware`, and rather than serve a request whose replay
     * protection was never established the handler refuses it. Everything after that is request
     * validation — the body must be a JSON object carrying string `operation` and `target` members,
     * the operation must name a `SafePlanOperation` case, and the target must survive the plan's own
     * length and character rules — and each of those failures is answered 400 with the sentence that
     * rejected it as the problem detail.
     *
     * The response is marked `no-store` and tagged with an entity tag built from the plan identifier,
     * since each request mints a fresh plan that expires rather than a cacheable resource.
     *
     * @param   ServerRequestInterface  $request  Request whose JSON body names the operation and target.
     *
     * @return  ResponseInterface  The plan as JSON with a 200 status, or a 400 problem document.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE) instanceof IdempotencyKey) {
            return $this->problems->create(
                400,
                'Idempotency Key Required',
                'The plan endpoint requires validated Idempotency-Key middleware.',
                'urn:kumwe:problem:idempotency-key-required',
                (string) $request->getUri(),
            );
        }

        try {
            $payload = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalidRequest($request, 'The request body must be valid JSON.');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return $this->invalidRequest($request, 'The request body must be a JSON object.');
        }

        $operationName = $payload['operation'] ?? null;
        $target = $payload['target'] ?? null;

        if (!is_string($operationName) || !is_string($target)) {
            return $this->invalidRequest($request, 'The operation and target members must be strings.');
        }

        try {
            $operation = SafePlanOperation::from($operationName);
            $plan = $this->plans->create($operation, $target);
        } catch (ValueError | \InvalidArgumentException $exception) {
            return $this->invalidRequest($request, $exception->getMessage());
        }

        return new JsonResponse(
            $plan->toArray(),
            200,
            [
                'Cache-Control' => 'no-store',
                'ETag' => '"plan-' . $plan->id() . '"',
            ],
        );
    }

    /**
     * Build the 400 problem document every validation failure on this route shares.
     *
     * Only the detail sentence varies between the body, member and operation checks, so the status,
     * title, problem type and instance are fixed here and each caller supplies what it rejected.
     *
     * @param   ServerRequestInterface  $request  Request whose URI is recorded as the problem `instance`.
     * @param   string                  $detail   Operator-facing sentence naming what was wrong.
     *
     * @return  ResponseInterface  An `application/problem+json` response with a 400 status.
     *
     * @since   2.0.0
     */
    private function invalidRequest(ServerRequestInterface $request, string $detail): ResponseInterface
    {
        return $this->problems->create(
            400,
            'Invalid Plan Request',
            $detail,
            'urn:kumwe:problem:invalid-plan-request',
            (string) $request->getUri(),
        );
    }
}
