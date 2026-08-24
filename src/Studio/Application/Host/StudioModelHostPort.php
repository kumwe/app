<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use stdClass;

/**
 * Read-only Studio model port backed exclusively by the authorized AP-2 projection.
 *
 * @since  2.0.0
 */
final readonly class StudioModelHostPort
{
    /**
     * Bind the port to the sole authorized exact Content-model projection service.
     *
     * @param  StudioContentProjectionService  $models  Authorized exact Content-model projection.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioContentProjectionService $models)
    {
    }

    /**
     * Dispatch `model.get` and `model.list` without exposing denied model coordinates.
     *
     * @param   ExecutionContext           $context    Trusted actor and site.
     * @param   string                     $operation  Canonical model operation.
     * @param   StudioHostRequest          $request    Validated host envelope.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session snapshot.
     *
     * @return  StudioHostResult  Canonical model document or ordered list.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        unset($snapshot);
        $this->assertReadContext($request);

        try {
            return match ($operation) {
                'get' => $this->get($context, $request),
                'list' => $this->list($context, $request),
                default => throw new StudioHostOperationRefused(
                    'incompatible',
                    'studio.host/operation-unavailable',
                ),
            };
        } catch (StudioProjectionRejected) {
            throw new StudioHostOperationRefused('not-found', 'studio.model/not-found');
        }
    }

    /**
     * Resolve `model.get` for one exact trusted reference.
     *
     * @param   ExecutionContext   $context  Trusted actor and site context.
     * @param   StudioHostRequest  $request  Validated exact host envelope.
     *
     * @return  StudioHostResult  Exact canonical model document.
     *
     * @since   2.0.0
     */
    private function get(ExecutionContext $context, StudioHostRequest $request): StudioHostResult
    {
        $arguments = $request->arguments;
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['reference']) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $reference = $arguments->reference;
        if (!$reference instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = self::members($reference);
        $allowedMembers = [
            ['id', 'version'],
            ['id', 'revision', 'version'],
            ['id', 'integrity', 'version'],
            ['id', 'integrity', 'revision', 'version'],
        ];
        if (!in_array($members, $allowedMembers, true)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $id = $reference->id ?? null;
        $version = $reference->version ?? null;
        $revision = $reference->revision ?? null;
        if (
            !is_string($id)
            || $id === ''
            || !is_string($version)
            || $version === ''
            || ($revision !== null && !is_string($revision))
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $model = $this->models->model($context, $id, $version);
        if (
            $revision !== null
            && (!isset($model->revision)
                || !is_string($model->revision)
                || !hash_equals($model->revision, $revision))
        ) {
            throw new StudioHostOperationRefused('not-found', 'studio.model/not-found');
        }

        return new StudioHostResult($model, is_string($model->revision ?? null) ? $model->revision : null);
    }

    /**
     * Resolve `model.list` as a deterministic authorized model projection.
     *
     * @param   ExecutionContext   $context  Trusted actor and site context.
     * @param   StudioHostRequest  $request  Validated exact host envelope.
     *
     * @return  StudioHostResult  Ordered canonical model documents.
     *
     * @since   2.0.0
     */
    private function list(ExecutionContext $context, StudioHostRequest $request): StudioHostResult
    {
        if (!$request->arguments instanceof stdClass || get_object_vars($request->arguments) !== []) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $models = $this->models->models($context);
        usort($models, static fn (stdClass $left, stdClass $right): int => strcmp(
            self::modelSortKey($left),
            self::modelSortKey($right),
        ));

        return new StudioHostResult($models);
    }

    /**
     * Build a deterministic key only from string-valued canonical model coordinates.
     *
     * @param   stdClass  $model  Schema-validated model projection.
     *
     * @return  string  Identifier and version joined by an unambiguous separator.
     *
     * @since   2.0.0
     */
    private static function modelSortKey(stdClass $model): string
    {
        $id = $model->id ?? null;
        $version = $model->version ?? null;

        return (is_string($id) ? $id : '') . "\0" . (is_string($version) ? $version : '');
    }

    /**
     * Refuse write-context envelope members on the read-only model port.
     *
     * @param   StudioHostRequest  $request  Validated exact host envelope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertReadContext(StudioHostRequest $request): void
    {
        if ($request->expectedRevision !== null || $request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Return deterministic object member names for exact envelope validation.
     *
     * @param   stdClass  $document  Candidate protocol object.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private static function members(stdClass $document): array
    {
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);

        return $members;
    }
}
