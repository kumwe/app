<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Delivery\Api;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Application\ExportArtifactUnavailable;
use Kumwe\App\BusinessReporting\Application\ExportService;
use Kumwe\App\BusinessReporting\Application\ReportExecutionRequest;
use Kumwe\App\BusinessReporting\Application\ReportRowLimitExceeded;
use Kumwe\App\BusinessReporting\Application\ReportService;
use Kumwe\App\BusinessReporting\Application\ReportUnavailable;
use Kumwe\App\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Thin REST adapter for synchronous reports and queued export request, status and download.
 *
 * @since  2.0.0
 */
final readonly class ReportApiHandler implements RequestHandlerInterface
{
    /**
     * Local ceiling for a report JSON object when no upstream parser supplied one.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAX_JSON_BODY_BYTES = 2_097_152;

    /**
     * Route attribute selecting the trusted report operation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string OPERATION_ATTRIBUTE = 'kumwe.business_report.operation';

    /**
     * Wire transport parsing to the shared application services and presenter.
     *
     * @param  ReportService                  $reports    Synchronous policy-aware executor and discovery authority.
     * @param  ExportService                  $exports    Queued export use cases.
     * @param  ReportApiPresenter             $presenter  Stable safe JSON projection.
     * @param  StreamFactoryInterface         $streams    PSR stream wrapper for verified resources.
     * @param  ProblemDetailsResponseFactory  $problems   Stable RFC 9457 validation responder.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ReportService $reports,
        private ExportService $exports,
        private ReportApiPresenter $presenter,
        private StreamFactoryInterface $streams,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * Dispatch a route-declared operation without inferring behavior from a path.
     *
     * @param   ServerRequestInterface  $request  Authenticated API request with trusted route attributes.
     *
     * @return  ResponseInterface  No-store JSON, verified download, validation problem, or non-enumerating 404.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = ApiExecutionContext::fromRequest($request);
        $operation = $this->operation($request);
        $report = $request->getAttribute('report');
        $artifact = $request->getAttribute('artifact');
        try {
            if ($operation === 'report.list') {
                $items = [];
                foreach ($this->reports->available($context) as $definition) {
                    $items[] = $this->presenter->definition(
                        $definition,
                        '/api/v1/business/reports',
                        $this->reports->isAvailable(
                            $context,
                            $definition,
                            BusinessRecordQueryPurpose::Export,
                        ),
                    );
                }
                return new JsonResponse(['items' => $items], 200, ['Cache-Control' => 'no-store']);
            }
            if ($operation === 'report.execute' && is_string($report)) {
                $body = $this->body($request, ['parameters']);
                return new JsonResponse($this->presenter->report($this->reports->execute(
                    new ReportExecutionRequest(
                        $context,
                        $report,
                        $this->parameters($body),
                        $context->organization()?->identifier(),
                        BusinessRecordQueryPurpose::Report,
                    ),
                )), 200, ['Cache-Control' => 'no-store']);
            }
            if ($operation === 'report.export.request' && is_string($report)) {
                $body = $this->body($request, ['parameters', 'retention_seconds']);
                $retention = $body['retention_seconds'] ?? 86_400;
                if (!is_int($retention)) {
                    throw new InvalidArgumentException('Export retention must be an integer.');
                }
                $created = $this->exports->request(
                    $context,
                    $report,
                    $this->parameters($body),
                    $context->organization()?->identifier(),
                    $retention,
                );
                return new JsonResponse($this->presenter->export($created), 202, ['Cache-Control' => 'no-store']);
            }
            if ($operation === 'report.export.status' && is_string($artifact)) {
                return new JsonResponse(
                    $this->presenter->export($this->exports->status($context, $artifact)),
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            }
            if ($operation === 'report.export.download' && is_string($artifact)) {
                $download = $this->exports->download($context, $artifact);
                return new Response($this->streams->createStreamFromResource($download->stream), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Length' => (string) $download->size,
                    'Content-Disposition' => 'attachment; filename*=UTF-8\'\'' . rawurlencode($download->filename),
                    'ETag' => '"sha256-' . $download->checksum . '"',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        } catch (ExportArtifactUnavailable | ReportUnavailable) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        } catch (ReportRowLimitExceeded) {
            return $this->problem(
                $request,
                'Business report row limit exceeded',
                'The report is too large for an interactive response. Request a queued export instead.',
                'urn:kumwe:problem:business-report-row-limit-exceeded',
            );
        } catch (InvalidArgumentException) {
            return $this->problem(
                $request,
                'Invalid business report request',
                'The report request parameters are invalid.',
                'urn:kumwe:problem:business-report-validation-failed',
            );
        }

        return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
    }

    /**
     * Read one closed JSON request object and reject every unknown member.
     *
     * @param   ServerRequestInterface  $request  API request carrying a parsed or bounded raw JSON body.
     * @param   list<string>            $allowed  Exact permitted top-level member names.
     *
     * @return  array<string, mixed>  Validated JSON object.
     *
     * @since   2.0.0
     */
    private function body(ServerRequestInterface $request, array $allowed): array
    {
        $body = $request->getParsedBody();
        if ($body === null) {
            $source = $request->getBody();
            $size = $source->getSize();
            if ($size !== null && $size > self::MAX_JSON_BODY_BYTES) {
                throw new InvalidArgumentException('The report request body is missing or too large.');
            }
            if ($source->isSeekable()) {
                $source->rewind();
            }
            $encoded = '';
            while (!$source->eof() && strlen($encoded) <= self::MAX_JSON_BODY_BYTES) {
                $remaining = self::MAX_JSON_BODY_BYTES - strlen($encoded) + 1;
                $chunk = $source->read(min(8192, $remaining));
                if ($chunk === '') {
                    if (!$this->streamAtEnd($source)) {
                        throw new InvalidArgumentException('The report request body could not be read.');
                    }
                    break;
                }
                $encoded .= $chunk;
            }
            if ($encoded === '' || strlen($encoded) > self::MAX_JSON_BODY_BYTES || !$source->eof()) {
                throw new InvalidArgumentException('The report request body is missing or too large.');
            }
            try {
                $object = json_decode($encoded, false, 32, JSON_THROW_ON_ERROR);
                $body = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('The report request body is invalid JSON.', 0, $exception);
            }
            if (!$object instanceof \stdClass || !is_array($body)) {
                throw new InvalidArgumentException('The report request body must be a JSON object.');
            }
        }
        if (
            !is_array($body)
            || ($body !== [] && array_is_list($body))
            || array_diff(array_keys($body), $allowed) !== []
        ) {
            throw new InvalidArgumentException('The report request body is invalid or has unknown keys.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * Recheck stream exhaustion after a read may have advanced its end-of-file state.
     *
     * @param   StreamInterface  $stream  Request stream whose current state is inspected.
     *
     * @return  bool  Whether the stream is now exhausted.
     *
     * @since   2.0.0
     */
    private function streamAtEnd(StreamInterface $stream): bool
    {
        return $stream->eof();
    }

    /**
     * Extract the optional string-keyed report parameter object.
     *
     * @param   array<string, mixed>  $body  Validated API request body.
     *
     * @return  array<string, mixed>  Report parameters, empty when omitted.
     *
     * @since   2.0.0
     */
    private function parameters(array $body): array
    {
        $parameters = $body['parameters'] ?? [];
        if (!is_array($parameters) || ($parameters !== [] && array_is_list($parameters))) {
            throw new InvalidArgumentException('Report parameters must form a JSON object.');
        }

        /** @var array<string, mixed> $parameters */
        return $parameters;
    }

    /**
     * Render one fixed validation problem without publishing an exception or caller-supplied value.
     *
     * @param   ServerRequestInterface  $request  Request whose URI identifies this problem occurrence.
     * @param   string                  $title    Stable short problem title.
     * @param   string                  $detail   Fixed safe client guidance.
     * @param   string                  $type     Stable absolute problem type.
     *
     * @return  ResponseInterface  No-store RFC 9457 response with status 422.
     *
     * @since   2.0.0
     */
    private function problem(
        ServerRequestInterface $request,
        string $title,
        string $detail,
        string $type,
    ): ResponseInterface {
        return $this->problems->create(
            422,
            $title,
            $detail,
            $type,
            (string) $request->getUri(),
        )->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Resolve an explicit route operation or one of the documented method and path pairs.
     *
     * Ordinary Mezzio route declarations cannot publish arbitrary request attributes, so the closed
     * path fallback lets the composition root mount this handler directly while retaining an explicit
     * attribute seam for adapter tests and deployments that add a route-operation middleware.
     *
     * @param   ServerRequestInterface  $request  Matched API request.
     *
     * @return  string  One closed operation token, or an empty string for an unknown route.
     *
     * @since   2.0.0
     */
    private function operation(ServerRequestInterface $request): string
    {
        $operation = $request->getAttribute(self::OPERATION_ATTRIBUTE);
        if (is_string($operation) && $operation !== '') {
            return $operation;
        }

        $method = strtoupper($request->getMethod());
        $path = rtrim($request->getUri()->getPath(), '/');

        return match (true) {
            $method === 'GET' && $path === '/api/v1/business/reports' => 'report.list',
            $method === 'POST'
                && preg_match('#^/api/v1/business/reports/[^/]+/exports$#D', $path) === 1
                => 'report.export.request',
            $method === 'POST'
                && preg_match('#^/api/v1/business/reports/[^/]+$#D', $path) === 1
                => 'report.execute',
            $method === 'GET'
                && preg_match('#^/api/v1/business/report-exports/[^/]+/download$#D', $path) === 1
                => 'report.export.download',
            $method === 'GET'
                && preg_match('#^/api/v1/business/report-exports/[^/]+$#D', $path) === 1
                => 'report.export.status',
            default => '',
        };
    }
}
