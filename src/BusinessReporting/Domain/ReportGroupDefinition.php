<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportDefinitionGuard;

/**
 * One typed output column used as a grouping key.
 *
 * @since  2.0.0
 */
final readonly class ReportGroupDefinition
{
    /**
     * Name the column whose already-disclosed value forms this grouping key.
     *
     * @param  string  $columnAlias  Alias declared by a report column.
     *
     * @since  2.0.0
     */
    public function __construct(public string $columnAlias)
    {
        ReportDefinitionGuard::handle($columnAlias, 'group column');
    }
}
