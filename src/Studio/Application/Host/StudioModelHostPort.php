<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Studio\Application\Projection\StudioProjectionRejected;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\ModelPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Read-only Studio model port backed exclusively by the authorized AP-2 projection.
 *
 * @since  2.0.0
 */
final readonly class StudioModelHostPort implements ModelPortInterface
{
    /**
     * Bind the port to the sole authorized exact Content-model projection service.
     *
     * @param  StudioContentProjectionService       $models     Authorized exact Content-model projection.
     * @param  StudioProducerRequestAuthority|null  $authority  Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioContentProjectionService $models,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped model port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->models, $authority);
    }

    /**
     * Resolve `model.get` for one exact trusted reference.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Exact canonical model document.
     *
     * @since   2.0.0
     */
    public function get(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $this->assertReadContext($context);
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['reference']) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $reference = $arguments->reference;
        if (!$reference instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = self::members($reference);
        $allowedMembers = [
            ['id', 'version'],
            ['id', 'revision', 'version'],
            ['id', 'integrity', 'version'],
            ['id', 'integrity', 'revision', 'version'],
        ];
        if (!in_array($members, $allowedMembers, true)) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
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
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        try {
            $model = $this->models->model($authority->context(), $id, $version);
        } catch (StudioProjectionRejected) {
            StudioProducerError::refuse('not-found', 'studio.model/not-found');
        }
        if (
            $revision !== null
            && (!isset($model->revision)
                || !is_string($model->revision)
                || !hash_equals($model->revision, $revision))
        ) {
            StudioProducerError::refuse('not-found', 'studio.model/not-found');
        }

        return new HostResult($model, is_string($model->revision ?? null) ? $model->revision : null);
    }

    /**
     * Resolve `model.list` as a deterministic authorized model projection.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Ordered canonical model documents.
     *
     * @since   2.0.0
     */
    public function list(mixed $arguments, RequestContext $context): HostResult
    {
        $authority = $this->requestAuthority();
        $this->assertReadContext($context);
        if (!$arguments instanceof stdClass || get_object_vars($arguments) !== []) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        try {
            $models = $this->models->models($authority->context());
        } catch (StudioProjectionRejected) {
            StudioProducerError::refuse('not-found', 'studio.model/not-found');
        }
        usort($models, static fn (stdClass $left, stdClass $right): int => strcmp(
            self::modelSortKey($left),
            self::modelSortKey($right),
        ));

        return new HostResult($models);
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
     * @param   RequestContext  $context  Validated Producer request context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertReadContext(RequestContext $context): void
    {
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Require the per-request authority installed by the Producer host factory.
     *
     * @return  StudioProducerRequestAuthority  Trusted evidence for this dispatch.
     *
     * @since   2.0.0
     */
    private function requestAuthority(): StudioProducerRequestAuthority
    {
        return $this->authority ?? throw new \LogicException('A Studio model port requires request authority.');
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
