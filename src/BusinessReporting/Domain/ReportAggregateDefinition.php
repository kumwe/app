<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;

/**
 * One bounded aggregate over an already-disclosed report column.
 *
 * @since  2.0.0
 */
final readonly class ReportAggregateDefinition
{
    /**
     * Pair an output alias with a function and its optional source column.
     *
     * @param   string                   $alias        Stable result key.
     * @param   ReportAggregateFunction  $function     Aggregate to compute.
     * @param   ?string                  $columnAlias  Source column, absent exactly for count.
     *
     * @throws  InvalidArgumentException  When the function and column pairing is inconsistent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $alias,
        public ReportAggregateFunction $function,
        public ?string $columnAlias = null,
    ) {
        ReportDefinitionGuard::handle($alias, 'aggregate alias');
        if (($function === ReportAggregateFunction::Count) !== ($columnAlias === null)) {
            throw new InvalidArgumentException('Only a count report aggregate omits its source column.');
        }
        if ($columnAlias !== null) {
            ReportDefinitionGuard::handle($columnAlias, 'aggregate column');
        }
    }
}
