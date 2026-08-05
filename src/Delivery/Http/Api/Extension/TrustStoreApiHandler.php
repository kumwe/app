<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Extension;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class TrustStoreApiHandler implements RequestHandlerInterface
{
    public function __construct(private TrustStore $trust, private ProblemDetailsResponseFactory $problems)
    {
    }

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

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $body */
    private function string(array $body, string $name): string
    {
        $value = $body[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $name));
        }
        return trim($value);
    }

    /** @param array<string, mixed> $body */
    private function optional(array $body, string $name): ?string
    {
        $value = $body[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $body */
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
