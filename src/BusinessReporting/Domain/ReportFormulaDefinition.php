<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Domain;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;

/**
 * One bounded expression evaluated only over disclosure-safe report output aliases.
 *
 * @since  2.0.0
 */
final readonly class ReportFormulaDefinition
{
    /**
     * Declare a formula and its stable output metadata.
     *
     * @param   string           $alias       Stable output key.
     * @param   string           $label       Human label shown by adapters.
     * @param   ReportValueType  $type        Expected scalar result type.
     * @param   Expression       $expression  Validated bounded expression tree.
     *
     * @throws  InvalidArgumentException  When the alias or label is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $alias,
        public string $label,
        public ReportValueType $type,
        public Expression $expression,
    ) {
        ReportDefinitionGuard::handle($alias, 'formula alias');
        if ($label === '' || mb_strlen($label) > 191 || preg_match('/[\x00-\x1f]/', $label) === 1) {
            throw new InvalidArgumentException('A report formula label is invalid.');
        }
    }
}
