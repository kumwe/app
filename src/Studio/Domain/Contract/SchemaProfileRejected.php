<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Contract;

use RuntimeException;

/**
 * A deterministic admission refusal of a contributed property schema.
 *
 * The profile publishes a closed code set and a JSON Pointer to the rejected schema location, and
 * the language-neutral corpus compares exactly those two values across runtimes. The message is
 * for humans and is not a conformance value.
 *
 * @since  2.0.0
 */
final class SchemaProfileRejected extends RuntimeException
{
    /**
     * Name the closed rejection code and the schema location it points at.
     *
     * @param  string  $rejection   One of `invalid-root`, `unsupported-keyword`,
     *         `invalid-keyword-value`, `unsafe-member`, `limit-exceeded`, `invalid-reference`
     *         or `recursive-schema`.
     * @param  string  $schemaPath  JSON Pointer to the rejected schema location; the empty string
     *         is the root.
     * @param  string  $message     Human-readable explanation; not a conformance value.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $rejection,
        public readonly string $schemaPath,
        string $message,
    ) {
        parent::__construct($message);
    }
}
