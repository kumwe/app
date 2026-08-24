<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewHostPort;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use stdClass;

/**
 * Central host dispatcher that fences every canonical operation before any port implementation runs.
 *
 * The dispatcher implements permission directly and delegates artifact, recovery, preview and media behaviour
 * to their application ports. Every operation crosses the same stale-generation and trusted-context fence first;
 * no persistence adapter can be reached through a client-asserted identity or obsolete authority snapshot.
 *
 * @since  2.0.0
 */
final readonly class StudioHostDispatcher
{
    /**
     * The sole wire version this exact vendored protocol pin accepts.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string PROTOCOL_VERSION = '0.1.0-draft.2';

    /**
     * Bind canonical envelope decoding to the live host authority resolver.
     *
     * @param  StudioHostRequestDecoder         $decoder       Closed pinned-schema decoder.
     * @param  StudioHostSessionAuthority       $authority     Trusted identity, permission and generation boundary.
     * @param  StudioArtifactHostPort|null      $artifact      Versioned artifact port when composed.
     * @param  StudioRecoveryHostPort|null      $recovery      Scoped recovery port when composed.
     * @param  StudioPreviewHostPort|null       $preview       Authenticated preview port when composed.
     * @param  StudioMediaHostPort|null         $media         Complete media port when composed.
     * @param  StudioModelHostPort|null         $model         Exact Content-model query port when composed.
     * @param  StudioLocalizationHostPort|null  $localization  Compiled Studio message port when composed.
     * @param  StudioTelemetryHostPort|null     $telemetry     Bounded observability port when composed.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostRequestDecoder $decoder,
        private StudioHostSessionAuthority $authority,
        private ?StudioArtifactHostPort $artifact = null,
        private ?StudioRecoveryHostPort $recovery = null,
        private ?StudioPreviewHostPort $preview = null,
        private ?StudioMediaHostPort $media = null,
        private ?StudioModelHostPort $model = null,
        private ?StudioLocalizationHostPort $localization = null,
        private ?StudioTelemetryHostPort $telemetry = null,
    ) {
    }

    /**
     * Dispatch one normative `{port}/{operation}` request using only trusted App identity.
     *
     * @param   ExecutionContext             $context           Fresh authenticated App context.
     * @param   string                       $port              Canonical route port segment.
     * @param   string                       $operation         Canonical route operation segment.
     * @param   mixed                        $document          Decoded host-request candidate.
     * @param   StudioPreviewTransport|null  $previewTransport  HTTP-only evidence for preview operations.
     *
     * @return  StudioHostOutcome  Canonical result or non-disclosing host error.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        string $port,
        string $operation,
        mixed $document,
        ?StudioPreviewTransport $previewTransport = null,
    ): StudioHostOutcome {
        try {
            $request = $this->decoder->decode($document);
        } catch (StudioHostRequestRejected) {
            return self::refusal('invalid-request', 'studio.host/invalid-request');
        }
        $routeOperation = self::routeOperation($port, $operation);
        if ($routeOperation === null || !hash_equals($routeOperation, $request->operationId)) {
            return self::refusal('invalid-request', 'studio.host/operation-mismatch');
        }
        if (!hash_equals(self::PROTOCOL_VERSION, $request->protocolVersion)) {
            return self::refusal('incompatible', 'studio.host/protocol-incompatible');
        }

        try {
            $snapshot = $this->authority->resolve($context, $request->resourceContextKey);
        } catch (StudioHostAccessRefused $refused) {
            return self::refusal($refused->category, $refused->diagnosticCode);
        }
        if (
            !hash_equals($snapshot->session->sessionGeneration, $request->sessionGeneration)
            || !hash_equals($snapshot->generation, $request->sessionGeneration)
        ) {
            return self::refusal('invalid-request', 'studio.host/stale-session-generation');
        }
        if (!$snapshot->modeAllowed) {
            return self::refusal('forbidden', 'studio.host/session-refused');
        }

        if (
            in_array(
                $request->operationId,
                [
                    'studio.operation/media.abort-upload',
                    'studio.operation/media.authorize-upload',
                    'studio.operation/media.complete-upload',
                    'studio.operation/media.get',
                    'studio.operation/media.import-external',
                    'studio.operation/media.list',
                    'studio.operation/media.upload-status',
                ],
                true,
            )
        ) {
            return $this->media?->dispatch($context, $request, $snapshot)
                ?? self::refusal('incompatible', 'studio.host/operation-unavailable');
        }

        try {
            $result = match (true) {
                $request->operationId === 'studio.operation/permission.explain' =>
                    new StudioHostResult($this->explainValue($request, $snapshot)),
                $request->operationId === 'studio.operation/permission.refresh' =>
                    new StudioHostResult($this->refreshValue($request, $snapshot)),
                $port === 'artifact' && $this->artifact !== null =>
                    $this->artifact->dispatch($operation, $request, $snapshot),
                $port === 'recovery' && $this->recovery !== null =>
                    $this->recovery->dispatch($operation, $request, $snapshot),
                in_array(
                    $request->operationId,
                    ['studio.operation/preview.cancel', 'studio.operation/preview.render'],
                    true,
                ) && $previewTransport === null => throw new StudioHostOperationRefused(
                    'invalid-request',
                    'studio.preview/invalid-transport',
                ),
                in_array(
                    $request->operationId,
                    ['studio.operation/preview.cancel', 'studio.operation/preview.render'],
                    true,
                ) && $this->preview !== null => new StudioHostResult($this->preview->dispatch(
                    $context,
                    $operation,
                    $request,
                    $snapshot,
                    $previewTransport,
                )),
                $port === 'model' && $this->model !== null =>
                    $this->model->dispatch($context, $operation, $request, $snapshot),
                $port === 'localization' && $this->localization !== null =>
                    $this->localization->dispatch($operation, $request, $snapshot),
                $port === 'telemetry' && $this->telemetry !== null =>
                    $this->telemetry->dispatch($operation, $request, $snapshot),
                default => throw new StudioHostOperationRefused(
                    'incompatible',
                    'studio.host/operation-unavailable',
                ),
            };
        } catch (StudioPreviewRefused $refused) {
            return self::refusal($refused->category, $refused->diagnosticCode);
        } catch (StudioHostOperationRefused $refused) {
            return self::refusal(
                $refused->category,
                $refused->diagnosticCode,
                $refused->revision,
                $refused->retryable,
                $refused->retryAfterMilliseconds,
            );
        }

        return new StudioHostOutcome(200, $result->document());
    }

    /**
     * Answer whether the active server-side snapshot carries one canonical Studio permission.
     *
     * @param   StudioHostRequest          $request   Valid permission-explanation request.
     * @param   StudioHostSessionSnapshot  $snapshot  Live trusted authorization snapshot.
     *
     * @return  stdClass  Permission explanation value.
     *
     * @since   2.0.0
     */
    private function explainValue(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): stdClass
    {
        $arguments = $request->arguments;
        $members = $arguments instanceof stdClass ? array_keys(get_object_vars($arguments)) : [];
        sort($members, SORT_STRING);
        if (
            !$arguments instanceof stdClass
            || $members !== ['operation']
            || !is_string($arguments->operation)
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $allowed = $this->authority->permits($snapshot, $arguments->operation);
        $value = new stdClass();
        $value->allowed = $allowed;
        if (!$allowed) {
            $value->reason = (object) [
                'key' => 'studio.permission/withheld',
                'defaultMessage' => 'This action is not available in the current Studio session.',
            ];
        }

        return $value;
    }

    /**
     * Return the complete live permission snapshot for a current generation.
     *
     * @param   StudioHostRequest          $request   Valid permission-refresh request.
     * @param   StudioHostSessionSnapshot  $snapshot  Live trusted authorization snapshot.
     *
     * @return  stdClass  Current sorted permissions and generation.
     *
     * @since   2.0.0
     */
    private function refreshValue(StudioHostRequest $request, StudioHostSessionSnapshot $snapshot): stdClass
    {
        $arguments = $request->arguments;
        if (
            $arguments !== null
            && (!$arguments instanceof stdClass || get_object_vars($arguments) !== [])
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return (object) [
            'permissions' => $snapshot->permissions,
            'sessionGeneration' => $snapshot->generation,
        ];
    }

    /**
     * Derive the operation capability that the two normative route segments must name.
     *
     * @param   string  $port       Candidate port route segment.
     * @param   string  $operation  Candidate operation route segment.
     *
     * @return  string|null  Canonical operation capability, or null for malformed segments.
     *
     * @since   2.0.0
     */
    private static function routeOperation(string $port, string $operation): ?string
    {
        if (
            preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $port) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $operation) !== 1
        ) {
            return null;
        }

        return 'studio.operation/' . $port . '.' . $operation;
    }

    /**
     * Build a canonical host error without policy or resource details.
     *
     * @param   string       $category                Closed protocol error category.
     * @param   string       $code                    Delivery-safe canonical diagnostic code.
     * @param   string|null  $revision                Safe authoritative revision for a conflict, when present.
     * @param   bool         $retryable               Whether the caller may retry the same request.
     * @param   int|null     $retryAfterMilliseconds  Bounded retry delay when one is available.
     *
     * @return  StudioHostOutcome  Error document and matching HTTP status.
     *
     * @since   2.0.0
     */
    public static function refusal(
        string $category,
        string $code,
        ?string $revision = null,
        bool $retryable = false,
        ?int $retryAfterMilliseconds = null,
    ): StudioHostOutcome {
        $document = new stdClass();
        $document->contractVersion = '0.1-draft';
        $document->kind = 'host-error';
        $document->category = $category;
        $document->message = (object) [
            'key' => 'studio.host/request-refused',
            'defaultMessage' => 'The Studio host request could not be completed.',
        ];
        $document->retryable = $retryable;
        if ($revision !== null) {
            $document->revision = $revision;
        }
        if ($retryAfterMilliseconds !== null) {
            $document->retryAfterMilliseconds = $retryAfterMilliseconds;
        }
        $document->diagnostics = [(object) [
            'code' => $code,
            'severity' => 'blocking',
            'message' => (object) [
                'key' => $code,
                'defaultMessage' => $code === 'studio.host/stale-session-generation'
                    ? 'The Studio session authority changed and the session must be reopened.'
                    : 'The Studio host request was refused.',
            ],
        ]];

        return new StudioHostOutcome(match ($category) {
            'unauthenticated' => 401,
            'forbidden' => 403,
            'not-found' => 404,
            'conflict' => 409,
            'limit-exceeded' => 413,
            'validation-failed' => 422,
            'rate-limited' => 429,
            'unavailable' => 503,
            'internal' => 500,
            default => 400,
        }, $document);
    }
}
