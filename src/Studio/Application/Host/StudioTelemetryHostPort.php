<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
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
final readonly class StudioTelemetryHostPort
{
    /**
     * Bind client telemetry to the existing structured observability sink.
     *
     * @param  LoggerInterface  $logger  Existing structured observability sink.
     *
     * @since  2.0.0
     */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Validate and emit one bounded primitive-only Studio telemetry event.
     *
     * @param   string                     $operation  Canonical telemetry operation.
     * @param   StudioHostRequest          $request    Validated host envelope.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session snapshot.
     *
     * @return  StudioHostResult  Canonical empty acknowledgement.
     *
     * @since   2.0.0
     */
    public function dispatch(
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        if ($operation !== 'emit') {
            throw new StudioHostOperationRefused('incompatible', 'studio.host/operation-unavailable');
        }
        if ($request->expectedRevision !== null || $request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
        $arguments = $request->arguments;
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['event']) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $event = $arguments->event;
        if (!$event instanceof stdClass) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $members = self::members($event);
        if (!in_array($members, [['name'], ['attributes', 'name']], true)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $name = $event->name ?? null;
        if (
            !is_string($name)
            || strlen($name) > 120
            || preg_match('/^[a-z][a-z0-9.-]*\/[a-z][a-z0-9._-]*$/D', $name) !== 1
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.telemetry/invalid-event');
        }
        $attributes = $event->attributes ?? new stdClass();
        if (!$attributes instanceof stdClass || count(get_object_vars($attributes)) > 32) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.telemetry/invalid-attributes');
        }
        $attributeNames = [];
        foreach (get_object_vars($attributes) as $key => $value) {
            if (strlen($key) > 64 || preg_match('/^[a-z][a-z0-9._-]*$/D', $key) !== 1 || !self::primitive($value)) {
                throw new StudioHostOperationRefused('invalid-request', 'studio.telemetry/invalid-attributes');
            }
            if (is_string($value) && strlen($value) > 200) {
                throw new StudioHostOperationRefused('invalid-request', 'studio.telemetry/invalid-attributes');
            }
            $attributeNames[] = $key;
        }
        if (strlen(CanonicalJson::stringify($event)) > 4096) {
            throw new StudioHostOperationRefused('limit-exceeded', 'studio.telemetry/event-too-large');
        }
        sort($attributeNames, SORT_STRING);
        $this->logger->info('Studio client telemetry event.', [
            'studio_event' => $name,
            'attribute_count' => count($attributeNames),
            'attribute_names' => $attributeNames,
            'mode' => $snapshot->session->mode->value,
            'site_identifier' => $snapshot->session->siteId,
        ]);

        return new StudioHostResult(null);
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
