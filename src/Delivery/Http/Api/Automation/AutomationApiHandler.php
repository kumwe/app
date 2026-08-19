<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Automation;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Kumwe\App\Application\Automation\AutomationManagementService;
use Kumwe\App\Application\Automation\AutomationNotFound;
use Kumwe\App\Delivery\Http\Api\Concurrency\EntityTag;
use Kumwe\App\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\App\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Single PSR-15 handler behind every `/api/v1/schedules` and `/api/v1/jobs` route.
 *
 * All eight automation operations share one handler because they share one job: turn a method and path
 * into an `AutomationManagementService` call, and turn what comes back — a row, a new identifier,
 * nothing at all — into a JSON or empty response. Policy, authorization and auditing stay in the
 * service; what lives here is the wire contract. Every success response is `no-store`, because a
 * schedule can be disabled and a job can die between two polls and a cached listing would show an
 * operator a queue that no longer exists. Every modelled failure leaves as an RFC 9457 problem
 * document rather than an ad-hoc error body. A schedule read carries an `ETag` built from the stored
 * version, and the update and delete routes sit behind `RequireIfMatchMiddleware`, so a client that
 * edits a revision someone else has already replaced is refused instead of overwriting it.
 *
 * @since  2.0.0
 */
final readonly class AutomationApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the handler to the automation service and to the problem-document factory.
     *
     * @param  AutomationManagementService    $automation  Owns schedule and job policy, authorization and storage.
     * @param  ProblemDetailsResponseFactory  $problems    Renders the modelled failures as RFC 9457 documents.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AutomationManagementService $automation,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Dispatch the request to its automation operation and render the outcome.
     *
     * The schedule listing answers with the manageable schedules and the job types a new schedule may
     * name, so a client can populate a creation form from one round trip. Every failure raised while
     * dispatching is offered to `problem()`; the three this API models become problem documents and
     * anything else is re-thrown for the surrounding error middleware, because a failure the handler
     * cannot classify is not one to answer with a guessed status.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request, already past the capability check.
     *
     * @return  ResponseInterface  The operation's JSON or 204 response, or a problem document for a
     *          modelled failure.
     *
     * @since   2.0.0
     */
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

    /**
     * Resolve the request's method and path into the operation token the dispatcher matches on.
     *
     * The pairing is matched here rather than read back from the router's route name, so the handler
     * depends on nothing but the request itself. A method the API does not define on an otherwise
     * known path falls to the default arm and is refused, never treated as a neighbouring operation.
     *
     * @param   ServerRequestInterface  $request  Request whose method and path name the operation.
     *
     * @return  string  Dotted operation token, such as `schedules.update` or `jobs.retry`.
     *
     * @throws  InvalidArgumentException  When no automation operation matches that method and path pair.
     *
     * @since   2.0.0
     */
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

    /**
     * Answer a single-schedule read with the row and the entity tag a later edit must echo.
     *
     * The `ETag` is the point of reading one schedule rather than filtering the listing: it is the
     * only place a client obtains the `If-Match` value the update and delete routes demand.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the schedule.
     *
     * @return  ResponseInterface  200 carrying the schedule row, its `ETag` and `no-store`.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing, is not a canonical lowercase
     *          UUID, or the request carries no usable execution context.
     * @throws  AutomationNotFound  When no schedule carries that identifier.
     * @throws  DomainException  When the stored row carries no version to build an entity tag from.
     *
     * @since   2.0.0
     */
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

    /**
     * Store a new schedule described by the request body and answer with where to find it.
     *
     * Every field is required, `first_run` included: the API never guesses when a schedule starts.
     * `payload` must be a non-empty JSON object, because it reaches the job handler as its named
     * arguments — a list, and equally an omitted or empty object, is refused here rather than reaching
     * a handler that cannot read it.
     *
     * @param   ServerRequestInterface  $request  Request whose JSON body describes the schedule to store.
     *
     * @return  ResponseInterface  201 carrying the new identifier, with a `Location` header for the schedule.
     *
     * @throws  InvalidArgumentException  When the body is not a JSON object, a required field is absent or
     *          empty, `payload` is absent or is not a non-empty JSON object,
     *          `first_run` is not an RFC 3339 instant, or no handler is
     *          registered for the job type.
     *
     * @since   2.0.0
     */
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

    /**
     * Suspend or resume an existing schedule under an `If-Match` precondition.
     *
     * `enabled` is the only field an update may change; changing a schedule's timing or payload means
     * deleting it and creating another. The stored row is read first so the precondition is tested
     * against the version actually held — and before the body is looked at, so a client working from a
     * stale read is turned away whatever it sent. That same version travels into the service, which
     * checks it again inside the write transaction.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` attribute names the schedule and whose
     *          body carries the new `enabled` flag.
     *
     * @return  ResponseInterface  204 with `no-store`; the caller re-reads the schedule for its new version.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing or malformed, the body is not
     *          a JSON object, or `enabled` is absent or not a boolean.
     * @throws  AutomationNotFound  When no schedule carries that identifier.
     * @throws  AutomationPreconditionFailed  When the `If-Match` precondition does not name the stored version.
     * @throws  DomainException  When the stored row carries no version to compare the precondition against.
     *
     * @since   2.0.0
     */
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

    /**
     * Remove a schedule under an `If-Match` precondition, so no further occurrence is enqueued.
     *
     * Deletion is how a schedule with the wrong timing or payload is retired, since an update can only
     * toggle `enabled`, so the precondition matters as much here as it does there: the version the
     * client read is checked before the row goes away, and again by the service as it writes. Jobs the
     * schedule already enqueued are left alone and still run.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the schedule.
     *
     * @return  ResponseInterface  204 with `no-store`.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing or is not a canonical
     *          lowercase UUID.
     * @throws  AutomationNotFound  When no schedule carries that identifier.
     * @throws  AutomationPreconditionFailed  When the `If-Match` precondition does not name the stored version.
     * @throws  DomainException  When the stored row carries no version to compare the precondition against.
     *
     * @since   2.0.0
     */
    private function deleteSchedule(ServerRequestInterface $request): ResponseInterface
    {
        $id = $this->routeId($request);
        $schedule = $this->automation->schedule(ApiExecutionContext::fromRequest($request), $id);
        $version = $this->version($schedule);
        $this->assertExpectedVersion($request, $version);
        $this->automation->deleteSchedule(ApiExecutionContext::fromRequest($request), $id, $version);

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Requeue or withdraw one job named by the route, sharing the two routes' identical plumbing.
     *
     * Neither action takes an `If-Match`: a job's state is owned by the queue and moves without a
     * client edit, so the service decides whether the job is in a state the action applies to.
     *
     * @param   ServerRequestInterface  $request  Request whose `id` route attribute names the job.
     * @param   bool                    $retry    True to requeue a dead job, false to cancel a pending one.
     *
     * @return  ResponseInterface  204 with `no-store`; the job's new state is read back from the job listing.
     *
     * @throws  InvalidArgumentException  When the route identifier is missing or is not a canonical lowercase
     *          UUID, or the job does not exist or is not in a state the action applies to.
     *
     * @since   2.0.0
     */
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

    /**
     * Decode the request body as a JSON object.
     *
     * A top-level list is refused alongside scalars and `null`, because every automation body is keyed
     * and accepting a list here would only move the type failure into a field lookup further down.
     * Decoding is depth limited, so a deeply nested body is rejected rather than exhausting the stack.
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
     * Absent, wrongly typed and whitespace-only are one failure on purpose: a field that trims to
     * nothing tells the service as little as one that was never sent.
     *
     * @param   array<string, mixed>  $body   Decoded request body.
     * @param   string                $field  Wire name of the field, repeated verbatim in the failure message.
     *
     * @return  string  The field's value with surrounding whitespace removed.
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
     * Read the record identifier the router captured from the path.
     *
     * Only presence is checked here; whether the value is a canonical UUID is the service's decision,
     * so the same rule applies to an identifier from a path as to one from a body.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     *
     * @return  string  Value of the `id` route attribute, not yet validated as an identifier.
     *
     * @throws  InvalidArgumentException  When the route attribute is absent, empty, or not a string, which
     *          means the handler was mounted on a route without an `{id}` segment.
     *
     * @since   2.0.0
     */
    private function routeId(ServerRequestInterface $request): string
    {
        $id = $request->getAttribute('id');

        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('The automation route identifier is missing.');
        }

        return $id;
    }

    /**
     * Read the optimistic-concurrency version out of a schedule row.
     *
     * Drivers disagree on whether an integer column arrives as an int or a decimal string, so both
     * spellings are accepted. Anything else is corrupt storage rather than a client mistake, which is
     * why it raises a plain `DomainException`: that one is not mapped to a problem document and
     * surfaces as a server failure instead of blaming the request.
     *
     * @param   array<string, mixed>  $schedule  Normalised schedule row as the service returned it.
     *
     * @return  int  Stored version, from which this schedule's entity tag is derived.
     *
     * @throws  DomainException  When the row carries no `version`, or one that is neither an integer nor a
     *          string spelling a positive number without a leading zero.
     *
     * @since   2.0.0
     */
    private function version(array $schedule): int
    {
        $version = $schedule['version'] ?? null;

        if (!is_int($version) && (!is_string($version) || preg_match('/^[1-9][0-9]*$/D', $version) !== 1)) {
            throw new DomainException('The stored schedule version is invalid.');
        }

        return (int) $version;
    }

    /**
     * Refuse the request unless its `If-Match` precondition names the revision about to change.
     *
     * A missing precondition attribute fails here as well as a stale one. `RequireIfMatchMiddleware`
     * rejects a request that carries no usable header before the handler runs, so reaching this method
     * without one means the route was wired without that middleware, and treating that as "matched"
     * would silently drop the lost-update protection.
     *
     * @param   ServerRequestInterface  $request  Request carrying the precondition the middleware parsed.
     * @param   int                     $version  Version currently stored for the schedule being changed.
     *
     * @return  void
     *
     * @throws  AutomationPreconditionFailed  When no precondition was attached, or none of its tags matches
     *          the entity tag for the stored version.
     *
     * @since   2.0.0
     */
    private function assertExpectedVersion(ServerRequestInterface $request, int $version): void
    {
        $condition = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE);

        if (!$condition instanceof IfMatch || !$condition->matches(EntityTag::fromVersion($version))) {
            throw new AutomationPreconditionFailed();
        }
    }

    /**
     * Read how many jobs the listing should return, defaulting to 100 when the caller says nothing.
     *
     * The bound is enforced here rather than clamped, so a caller asking for more than the API will
     * ever return learns that instead of quietly receiving a shorter page than it planned around.
     *
     * @param   ServerRequestInterface  $request  Request whose query string may carry `limit`.
     *
     * @return  int  Page size between 1 and 500.
     *
     * @throws  InvalidArgumentException  When `limit` is not a decimal integer, or falls outside 1 to 500.
     *
     * @since   2.0.0
     */
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

    /**
     * Parse the `first_run` field as an RFC 3339 instant and express it in UTC.
     *
     * The shape is checked against a pattern before the value ever reaches `DateTimeImmutable`, whose
     * constructor also understands relative expressions such as `next friday` — accepting one of those
     * would turn a malformed field into a schedule starting at a time nobody chose. A value that parses
     * with warnings, such as an impossible day of month, is rejected for the same reason. Normalising
     * to UTC means the stored instant does not depend on the offset the client happened to send.
     *
     * @param   string  $value  Field value exactly as the client spelled it.
     *
     * @return  DateTimeImmutable  The same instant expressed in UTC.
     *
     * @throws  InvalidArgumentException  When the value is not RFC 3339 shaped, or does not name a real
     *          instant.
     *
     * @since   2.0.0
     */
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

    /**
     * Render the failures this API models as RFC 9457 problem documents.
     *
     * Exactly three are mapped: an absent schedule to 404, a stale `If-Match` to 412, and rejected
     * input to 422 — which is also where a job named by a retry or cancel turns out not to exist, since
     * the queue reports that as invalid input rather than as a missing resource. Every other failure is
     * re-thrown unchanged, which keeps an infrastructure fault or a programming error out of the 4xx
     * range and leaves it to the surrounding error middleware to log and answer. Each document quotes
     * the exception's own message, which these classes write as an operator-facing sentence rather than
     * by echoing what the client sent.
     *
     * @param   Throwable  $exception  Failure raised while dispatching the operation.
     * @param   string     $instance   Absolute request URI, recorded as the problem's `instance` member.
     *
     * @return  ResponseInterface  Problem document for one of the three modelled failures.
     *
     * @since   2.0.0
     */
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
