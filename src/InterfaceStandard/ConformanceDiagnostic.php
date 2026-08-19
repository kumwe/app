<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

use InvalidArgumentException;

/**
 * One deterministic, machine-addressable KIS conformance finding.
 *
 * @since  2.0.0
 */
final readonly class ConformanceDiagnostic
{
    /**
     * Validate a stable diagnostic code, declaration path, and operator-facing explanation.
     *
     * @param   string               $code      Stable dotted code used by tests and programme evidence.
     * @param   ConformanceSeverity  $severity  Whether the finding blocks admission.
     * @param   string               $path      Declaration field or indexed field where the problem occurred.
     * @param   string               $message   Complete sentence explaining the correction required.
     *
     * @throws  InvalidArgumentException  When diagnostic metadata is empty, unsafe, or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $code,
        public ConformanceSeverity $severity,
        public string $path,
        public string $message,
    ) {
        if (preg_match('/^kis\.[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $code) !== 1) {
            throw new InvalidArgumentException('A KIS diagnostic code must be a stable dotted identifier.');
        }
        if (preg_match('/^[a-z][a-z0-9_.\[\]-]{0,190}$/D', $path) !== 1) {
            throw new InvalidArgumentException('A KIS diagnostic path must identify one declaration field.');
        }
        if (trim($message) === '' || mb_strlen($message) > 500) {
            throw new InvalidArgumentException('A KIS diagnostic message must contain 1 to 500 characters.');
        }
    }

    /**
     * Export structured evidence for conformance tooling and extension diagnostics.
     *
     * @return  array{code: string, severity: string, path: string, message: string}  Canonical finding document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
