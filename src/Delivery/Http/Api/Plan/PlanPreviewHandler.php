<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

use JsonException;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValueError;

final readonly class PlanPreviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private SafePlanFactory $plans,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

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
