<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessReporting\Domain\ReportValueType;

/**
 * Bounded report result whose rows contain disclosure-safe scalar output only.
 *
 * @since  2.0.0
 */
final readonly class ReportExecutionResult
{
    /** @var list<array<string, bool|int|string|null>> @since 2.0.0 */
    public array $rows;

    /** @var array<string, string> @since 2.0.0 */
    public array $labels;

    /** @var array<string, ReportValueType> @since 2.0.0 */
    public array $types;

    /**
     * Hold one completed bounded result.
     *
     * @param   string                                    $reportIdentifier  Report handle.
     * @param   string                                    $definitionChecksum Exact definition checksum.
     * @param   string                                    $queryDigest       Canonical parameter and query digest.
     * @param   array<string, string>                     $labels            Output label by alias.
     * @param   array<string, ReportValueType>            $types             Output scalar type by alias.
     * @param   list<array<string, bool|int|string|null>> $rows              Safe materialized result rows.
     *
     * @throws  InvalidArgumentException  When result metadata or rows are malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $reportIdentifier,
        public string $definitionChecksum,
        public string $queryDigest,
        array $labels,
        array $types,
        array $rows,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/D', $definitionChecksum) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $queryDigest) !== 1
            || !array_is_list($rows)
        ) {
            throw new InvalidArgumentException('Report result metadata is invalid.');
        }
        if (array_keys($labels) !== array_keys($types)) {
            throw new InvalidArgumentException('Report result labels and types must name identical outputs.');
        }
        foreach ($types as $type) {
            if (!$type instanceof ReportValueType) {
                throw new InvalidArgumentException('A report result output type is invalid.');
            }
        }
        $this->labels = $labels;
        $this->types = $types;
        $this->rows = array_values($rows);
    }
}
