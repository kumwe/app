<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Contract;

/**
 * One instance-validation failure in the profile's portable diagnostic shape.
 *
 * The corpus compares the failing keyword and the instance JSON Pointer of the first diagnostic;
 * the message is a human-readable default and not a conformance value.
 *
 * @since  2.0.0
 */
final readonly class SchemaInstanceDiagnostic
{
    /**
     * Record one failure.
     *
     * @param  string  $instancePath  JSON Pointer to the failing instance location; `''` is the root.
     * @param  string  $keyword       The schema keyword that failed.
     * @param  string  $message       Human-readable default message.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $instancePath,
        public string $keyword,
        public string $message,
    ) {
    }
}
