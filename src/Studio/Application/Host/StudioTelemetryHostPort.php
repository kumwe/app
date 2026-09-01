<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\TelemetryPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Bounded low-cardinality telemetry boundary for the Studio browser runtime.
 *
 * Attribute values are validated but never copied into logs or metric labels. Operational records
 * retain only the event vocabulary, count and sorted attribute names.
 *
 * @since  2.0.0
 */
final readonly class StudioTelemetryHostPort implements TelemetryPortInterface
{
    /**
     * Bind client telemetry to the existing structured observability sink.
     *
     * @param  LoggerInterface                      $logger     Existing structured observability sink.
     * @param  StudioProducerRequestAuthority|null  $authority  Authorized Producer request scope, when bound.
     *
     * @since  2.0.0
     */
    public function __construct(
        private LoggerInterface $logger,
        private ?StudioProducerRequestAuthority $authority = null,
    ) {
    }

    /**
     * Bind this App-owned port implementation to one successfully authorized Producer request.
     *
     * @param   StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @return  self  Request-scoped telemetry port.
     *
     * @since   2.0.0
     */
    public function forRequest(StudioProducerRequestAuthority $authority): self
    {
        return new self($this->logger, $authority);
    }

    /**
     * Validate and emit one bounded primitive-only Studio telemetry event.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical empty acknowledgement.
     *
     * @since   2.0.0
     */
    public function emit(mixed $arguments, RequestContext $context): HostResult
    {
        $snapshot = $this->requestAuthority()->snapshot();
        if ($context->expectedRevision !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['event']) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $event = $arguments->event;
        if (!$event instanceof stdClass) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = self::members($event);
        if (!in_array($members, [['name'], ['attributes', 'name']], true)) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $name = $event->name ?? null;
        if (
            !is_string($name)
            || strlen($name) > 120
            || preg_match('/^[a-z][a-z0-9.-]*\/[a-z][a-z0-9._-]*$/D', $name) !== 1
        ) {
            StudioProducerError::refuse('invalid-request', 'studio.telemetry/invalid-event');
        }
        $attributes = $event->attributes ?? new stdClass();
        if (!$attributes instanceof stdClass || count(get_object_vars($attributes)) > 32) {
            StudioProducerError::refuse('invalid-request', 'studio.telemetry/invalid-attributes');
        }
        $attributeNames = [];
        foreach (get_object_vars($attributes) as $key => $value) {
            if (strlen($key) > 64 || preg_match('/^[a-z][a-z0-9._-]*$/D', $key) !== 1 || !self::primitive($value)) {
                StudioProducerError::refuse('invalid-request', 'studio.telemetry/invalid-attributes');
            }
            if (is_string($value) && strlen($value) > 200) {
                StudioProducerError::refuse('invalid-request', 'studio.telemetry/invalid-attributes');
            }
            $attributeNames[] = $key;
        }
        if (strlen(CanonicalJson::stringify($event)) > 4096) {
            StudioProducerError::refuse('limit-exceeded', 'studio.telemetry/event-too-large');
        }
        sort($attributeNames, SORT_STRING);
        $this->logger->info('Studio client telemetry event.', [
            'studio_event' => $name,
            'attribute_count' => count($attributeNames),
            'attribute_names' => $attributeNames,
            'mode' => $snapshot->session->mode->value,
            'site_identifier' => $snapshot->session->siteId,
        ]);

        return new HostResult(null);
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
        return $this->authority ?? throw new \LogicException('A Studio telemetry port requires request authority.');
    }

    /**
     * Decide whether one telemetry attribute is a finite protocol primitive.
     *
     * @param   mixed  $value  Candidate telemetry attribute value.
     *
     * @return  bool  True for a bounded protocol primitive type.
     *
     * @since   2.0.0
     */
    private static function primitive(mixed $value): bool
    {
        return $value === null || is_bool($value) || is_string($value) || is_int($value)
            || is_float($value) && is_finite($value);
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
