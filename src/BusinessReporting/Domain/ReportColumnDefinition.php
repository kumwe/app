<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;

/**
 * One disclosure-safe report column sourced from a root or included relation field.
 *
 * @since  2.0.0
 */
final readonly class ReportColumnDefinition
{
    /**
     * Declare one output column.
     *
     * @param   string           $alias       Stable output key used by grouping, formulas and sorting.
     * @param   string           $label       Human label shown by delivery adapters.
     * @param   string           $sourcePath  Root field or one-hop `relationship.field` path.
     * @param   ReportValueType  $type        Output scalar type.
     *
     * @throws  InvalidArgumentException  When an identifier or label is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $alias,
        public string $label,
        public string $sourcePath,
        public ReportValueType $type,
    ) {
        ReportDefinitionGuard::handle($alias, 'column alias');
        ReportDefinitionGuard::path($sourcePath, 'column source');
        if ($label === '' || mb_strlen($label) > 191 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $label) === 1) {
            throw new InvalidArgumentException('A report column label is invalid.');
        }
    }
}
