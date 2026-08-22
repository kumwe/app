<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * Validated intent to add one owned line through the single-line relationship command.
 *
 * The coordinator settles the pinned line definition, stable storage key and normalized values before
 * the write repository is called. Keeping those decisions together prevents the single-line path from
 * drifting away from the whole-document path while leaving transaction ownership with the facade.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineCreateIntent
{
    /**
     * Capture one fully validated owned-line creation.
     *
     * @param  ResolvedBusinessDefinition  $line       Pinned line definition matching the owner's line table.
     * @param  string                      $recordKey  Internal storage key assigned to the new line.
     * @param  string                      $recordId   Caller-facing identity assigned to the new line.
     * @param  array<string, mixed>        $values     Normalized, reference-resolved values ready for storage.
     *
     * @since  2.0.0
     */
    public function __construct(
        public ResolvedBusinessDefinition $line,
        public string $recordKey,
        public string $recordId,
        public array $values,
    ) {
    }
}
