<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Automation;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Application\Automation\AutomationNotFound;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

final readonly class AutomationApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private AutomationManagementService $automation,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return match ($this->operation($request)) {
                'schedules.list' => new JsonResponse([
                    'items' => $this->automation->schedules(ApiExecutionContext::fromRequest($request)),
                    'job_types' => $this->automation->jobTypes(ApiExecutionContext::fromRequest($request)),
                ], 200, ['Cache-Control' => 'no-store']),
                'schedules.read' => $this->schedule($request),
                'schedules.create' => $this->createSchedule($request),
                'schedules.update' => $this->updateSchedule($request),
                'schedules.delete' => $this->deleteSchedule($request),
                'jobs.list' => new JsonResponse([
                    'items' => $this->automation->jobs(
                        ApiExecutionContext::fromRequest($request),
                        $this->limit($request),
                    ),
                ], 200, ['Cache-Control' => 'no-store']),
                'jobs.retry' => $this->jobAction($request, true),
                'jobs.cancel' => $this->jobAction($request, false),
                default => throw new InvalidArgumentException('The automation operation is not supported.'),
            };
        } catch (Throwable $exception) {
            return $this->problem($exception, (string) $request->getUri());
        }
    }

    private function operation(ServerRequestInterface $request): string
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();

        return match (true) {
            $path === '/api/v1/schedules' && $method === 'GET' => 'schedules.list',
            $path === '/api/v1/schedules' && $method === 'POST' => 'schedules.create',
            preg_match('#^/api/v1/schedules/[^/]+$#D', $path) === 1 && $method === 'GET' => 'schedules.read',
            preg_match('#^/api/v1/schedules/[^/]+$#D', $path) === 1 && $method === 'PATCH' => 'schedules.update',
            preg_match('#^/api/v1/schedules/[^/]+$#D', $path) === 1 && $method === 'DELETE' => 'schedules.delete',
            $path === '/api/v1/jobs' && $method === 'GET' => 'jobs.list',
            preg_match('#^/api/v1/jobs/[^/]+/retry$#D', $path) === 1 && $method === 'POST' => 'jobs.retry',
            preg_match('#^/api/v1/jobs/[^/]+/cancel$#D', $path) === 1 && $method === 'POST' => 'jobs.cancel',
            default => throw new InvalidArgumentException('The automation operation is not supported.'),
        };
    }

    private function schedule(ServerRequestInterface $request): ResponseInterface
    {
        $schedule = $this->automation->schedule(
            ApiExecutionContext::fromRequest($request),
            $this->routeId($request),
        );

        return new JsonResponse($schedule, 200, [
            'ETag' => (string) EntityTag::fromVersion($this->version($schedule)),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function createSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->json($request);
        $payload = $body['payload'] ?? [];

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The schedule payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        $id = $this->automation->createSchedule(
            ApiExecutionContext::fromRequest($request),
            $this->string($body, 'name'),
            $this->string($body, 'cron_expression'),
            $this->string($body, 'timezone'),
            $this->string($body, 'job_type'),
            $payload,
            $this->string($body, 'queue'),
            $this->firstRun($this->string($body, 'first_run')),
        );

        return new JsonResponse(['id' => $id], 201, [
            'Location' => '/api/v1/schedules/' . $id,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function updateSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->routeId($request);
        $schedule = $this->automation->schedule(ApiExecutionContext::fromRequest($request), $id);
        $this->assertExpectedVersion($request, $this->version($schedule));
        $body = $this->json($request);
        $enabled = $body['enabled'] ?? null;

        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('The enabled field must be a boolean.');
        }

        $this->automation->setScheduleEnabled(
            ApiExecutionContext::fromRequest($request),
            $id,
            $this->version($schedule),
            $enabled,
        );

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function deleteSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->routeId($request);
        $schedule = $this->automation->schedule(ApiExecutionContext::fromRequest($request), $id);
        $version = $this->version($schedule);
        $this->assertExpectedVersion($request, $version);
        $this->automation->deleteSchedule(ApiExecutionContext::fromRequest($request), $id, $version);

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    private function jobAction(ServerRequestInterface $request, bool $retry): ResponseInterface
    {
        $id = $this->routeId($request);
        $context = ApiExecutionContext::fromRequest($request);

        if ($retry) {
            $this->automation->retryJob($context, $id);
        } else {
            $this->automation->cancelJob($context, $id);
        }

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

    private function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The automation route identifier is missing.');
        }

        return $id;
    }

    private function principal(ServerRequestInterface $request): AuthenticatedPrincipal
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new InvalidArgumentException('An authenticated principal is required.');
        }

        return $principal;
    }

    /** @param array<string, mixed> $schedule */
    private function version(array $schedule): int
    {
        $version = $schedule['version'] ?? null;

        if (!is_int($version) && (!is_string($version) || preg_match('/^[1-9][0-9]*$/D', $version) !== 1)) {
            throw new DomainException('The stored schedule version is invalid.');
        }

        return (int) $version;
    }

    private function assertExpectedVersion(ServerRequestInterface $request, int $version): void
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);

        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($version))) {
            throw new AutomationPreconditionFailed();
        }
    }

    private function limit(ServerRequestInterface $request): int
    {
        $value = $request->getQueryParams()['limit'] ?? '100';

        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The job list limit must be an integer between 1 and 500.');
        }

        $limit = (int) $value;

        if ($limit > 500) {
            throw new InvalidArgumentException('The job list limit must be an integer between 1 and 500.');
        }

        return $limit;
    }

    private function firstRun(string $value): DateTimeImmutable
    {
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidArgumentException('The first_run field must be an RFC 3339 date-time.');
        }

        try {
            $firstRun = new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('The first_run field must be a valid date-time.', 0, $exception);
        }

        $errors = DateTimeImmutable::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('The first_run field must be a valid date-time.');
        }

        return $firstRun->setTimezone(new DateTimeZone('UTC'));
    }

    private function problem(Throwable $exception, string $instance): ResponseInterface
    {
        return match (true) {
            $exception instanceof AutomationNotFound => $this->problems->create(
                404,
                'Automation Resource Not Found',
                $exception->getMessage(),
                'urn:kumwe:problem:automation-not-found',
                $instance,
            ),
            $exception instanceof AutomationPreconditionFailed => $this->problems->create(
                412,
                'Precondition Failed',
                $exception->getMessage(),
                'urn:kumwe:problem:precondition-failed',
                $instance,
            ),
            $exception instanceof InvalidArgumentException => $this->problems->create(
                422,
                'Unprocessable Automation Operation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                $instance,
            ),
            default => throw $exception,
        };
    }
}
