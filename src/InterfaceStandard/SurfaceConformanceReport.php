<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use InvalidArgumentException;

/**
 * Immutable result of evaluating one typed surface declaration against one KIS version.
 *
 * @since  2.0.0
 */
final readonly class SurfaceConformanceReport
{
    /**
     * Validated diagnostics in deterministic evaluation order.
     *
     * @var    list<ConformanceDiagnostic>
     * @since  2.0.0
     */
    private array $diagnostics;

    /**
     * Capture every finding produced for one declaration.
     *
     * @param   iterable<ConformanceDiagnostic>  $diagnostics  Deterministic findings from the validator.
     *
     * @throws  InvalidArgumentException  When the iterable contains something other than a diagnostic.
     *
     * @since   2.0.0
     */
    public function __construct(iterable $diagnostics)
    {
        $items = [];
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof ConformanceDiagnostic) {
                throw new InvalidArgumentException('A KIS conformance report accepts diagnostics only.');
            }
            $items[] = $diagnostic;
        }
        $this->diagnostics = $items;
    }

    /**
     * Whether the declaration has no admission-blocking finding.
     *
     * @return  bool  True when the report contains no error diagnostic.
     *
     * @since   2.0.0
     */
    public function conforms(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === ConformanceSeverity::Error) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return every finding without losing its declaration path or stable code.
     *
     * @return  list<ConformanceDiagnostic>  Deterministic validation findings.
     *
     * @since   2.0.0
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Export structured evidence without exception objects or framework state.
     *
     * @return  list<array{code: string, severity: string, path: string, message: string}>  Finding documents.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ConformanceDiagnostic $diagnostic): array => $diagnostic->toArray(),
            $this->diagnostics,
        );
    }
}
