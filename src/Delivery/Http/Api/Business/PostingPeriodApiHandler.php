<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordPostingPeriodConflict;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodService;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriod;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Delivery\Http\Api\Content\ContentApiRequest;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The REST surface for posting-period administration: listing, closing and re-opening ranges.
 *
 * The administrator screens-to-be, the CLI and this surface all drive `PostingPeriodService`, so the
 * capability gate and the audit entry apply here without being restated — this adapter only reads the
 * request and shapes the reply. One instance serves every `/api/v1/business-periods*` route,
 * dispatching on the request method and the trailing path segment, so listing and managing stay
 * separately routed and separately grantable.
 *
 * @since  2.0.0
 */
final readonly class PostingPeriodApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the REST surface to the service that owns the rules and the two failure renderers.
     *
     * @param  PostingPeriodService           $periods    Authorizes, records and audits every change.
     * @param  BusinessApiResponder           $responder  Maps the shared business failures onto RFC 9457
     *         problem documents.
     * @param  ProblemDetailsResponseFactory  $problems   Renders the period-conflict document this
     *         surface maps itself.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PostingPeriodService $periods,
        private BusinessApiResponder $responder,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Dispatch one request on its method and path, and answer any failure as a problem document.
     *
     * A declaration conflict — closing a closed period, re-opening an open one, re-declaring a key
     * over a different range — answers 409 under its own problem type; everything else defers to the
     * shared business responder, which rethrows what it does not recognise so a genuine fault still
     * surfaces as a fault.
     *
     * @param   ServerRequestInterface  $request  API request the authentication and authorization
     *          middleware have already accepted.
     *
     * @return  ResponseInterface  The listing, the changed declaration, or the problem document the
     *          failure maps to.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $context = ApiExecutionContext::fromRequest($request);
            $method = strtoupper($request->getMethod());
            $segments = explode('/', trim($request->getUri()->getPath(), '/'));
            $action = $segments[3] ?? null;

            if ($method === 'GET' && $action === null) {
                $query = $request->getQueryParams();
                $organization = is_string($query['organization'] ?? null) && $query['organization'] !== ''
                    ? $query['organization']
                    : null;

                return $this->json(['items' => array_map(
                    static fn (PostingPeriod $period): array => $period->toArray(),
                    $this->periods->list($context, $organization),
                )]);
            }
            if ($method === 'POST' && $action === 'close') {
                $body = ContentApiRequest::json($request);

                return $this->json($this->periods->close(
                    $context,
                    ContentApiRequest::requiredString($body, 'key'),
                    $this->instant($body, 'starts_at'),
                    $this->instant($body, 'ends_at'),
                    $this->organization($body),
                )->toArray());
            }
            if ($method === 'POST' && $action === 'reopen') {
                $body = ContentApiRequest::json($request);

                return $this->json($this->periods->reopen(
                    $context,
                    ContentApiRequest::requiredString($body, 'key'),
                    $this->organization($body),
                )->toArray());
            }

            throw new InvalidArgumentException('The requested posting period operation is not supported.');
        } catch (BusinessRecordPostingPeriodConflict $conflict) {
            return $this->problems->create(
                409,
                'Conflict',
                $conflict->getMessage(),
                'urn:kumwe:problem:posting-period-conflict',
                (string) $request->getUri(),
            );
        } catch (Throwable $exception) {
            return $this->responder->problem($exception, (string) $request->getUri());
        }
    }

    /**
     * Read one boundary member of the JSON body as the UTC instant the declaration stores.
     *
     * @param   array<string, mixed>  $body   Decoded JSON request body.
     * @param   string                $field  Member name: `starts_at` or `ends_at`.
     *
     * @return  DateTimeImmutable  The parsed instant in UTC.
     *
     * @throws  InvalidArgumentException  When the member is absent, or is neither a `YYYY-MM-DD` date
     *          nor an RFC 3339 UTC instant.
     *
     * @since   2.0.0
     */
    private function instant(array $body, string $field): DateTimeImmutable
    {
        $value = ContentApiRequest::requiredString($body, $field);
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) {
            return $date;
        }
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)$/D',
                $value,
            ) === 1
        ) {
            try {
                return (new DateTimeImmutable($value))->setTimezone($utc);
            } catch (\Exception $malformed) {
                throw new InvalidArgumentException(sprintf(
                    'The %s member is not a valid instant.',
                    $field,
                ), 0, $malformed);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'The %s member must be a YYYY-MM-DD date or an RFC 3339 UTC instant.',
            $field,
        ));
    }

    /**
     * Read the optional organization member of the JSON body.
     *
     * @param   array<string, mixed>  $body  Decoded JSON request body.
     *
     * @return  ?string  The organization identifier, or null for a site-wide declaration.
     *
     * @throws  InvalidArgumentException  When the member is present but not a string.
     *
     * @since   2.0.0
     */
    private function organization(array $body): ?string
    {
        $value = $body['organization'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('The organization member must be a string when supplied.');
        }

        return $value;
    }

    /**
     * Serialize a document as the JSON response every successful branch returns.
     *
     * @param   array<string, mixed>  $document  Body to encode.
     *
     * @return  ResponseInterface  A JSON response marked `Cache-Control: no-store`, because a period's
     *          status moves underneath a client that stores it.
     *
     * @since   2.0.0
     */
    private function json(array $document): ResponseInterface
    {
        return new JsonResponse($document, 200, ['Cache-Control' => 'no-store']);
    }
}
