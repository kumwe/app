<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

use InvalidArgumentException;

/**
 * One declared metric family, including the exact set of label values it is allowed to produce.
 *
 * Enumerating the label values is what makes cardinality a checkable property rather than a hope. A
 * metric family's series count is the product of its label value counts, so a definition that lists
 * them states its own upper bound: `kumwe_http_requests_total` with eight methods and five status
 * classes can never exceed forty series, no matter what a caller passes. Anything outside the
 * enumeration is folded into `other` by `MetricCatalog`, so a hostile or careless caller cannot grow
 * the series count at all.
 *
 * @since  2.0.0
 */
final readonly class MetricDefinition
{
    /**
     * Label value substituted for anything outside a label's enumerated set.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OTHER = 'other';

    /**
     * Declare one metric family.
     *
     * @param   string                       $name     Exposition name, already carrying the `kumwe_` prefix.
     * @param   MetricType                   $type     Shape the exposition declares for this family.
     * @param   string                       $help     One-line description written into the `# HELP` line.
     * @param   array<string, list<string>>  $labels   Allowed values per label name, in exposition order.
     * @param   list<float>                  $buckets  Upper bounds for a histogram, ascending; empty otherwise.
     *
     * @throws  InvalidArgumentException  When a histogram declares no buckets, or a non-histogram declares some.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $labels = [],
        public array $buckets = [],
    ) {
        if ($type === MetricType::Histogram && $buckets === []) {
            throw new InvalidArgumentException('A histogram must declare its buckets.');
        }
        if ($type !== MetricType::Histogram && $buckets !== []) {
            throw new InvalidArgumentException('Only a histogram may declare buckets.');
        }
        foreach ($labels as $values) {
            if ($values === []) {
                throw new InvalidArgumentException('A label must enumerate at least one allowed value.');
            }
        }
    }

    /**
     * Fold a caller-supplied label set onto the enumerated one.
     *
     * Labels the definition does not declare are dropped rather than passed through, and declared
     * labels the caller omitted are filled with `other`, so every series this family emits has exactly
     * the declared label names in the declared order — which the exposition format requires and which
     * keeps one family from splitting into several incompatible ones.
     *
     * @param   array<string, string>  $labels  Labels the caller offered.
     *
     * @return  array<string, string>  Labels restricted to the enumeration, in declaration order.
     *
     * @since   2.0.0
     */
    public function bind(array $labels): array
    {
        $bound = [];
        foreach ($this->labels as $name => $allowed) {
            $value = $labels[$name] ?? null;
            $bound[$name] = is_string($value) && in_array($value, $allowed, true) ? $value : self::OTHER;
        }

        return $bound;
    }

    /**
     * Report the largest number of time series this family can ever produce.
     *
     * @return  int<1, max>  Product of the enumerated label value counts, or one for an unlabelled family.
     *
     * @since   2.0.0
     */
    public function maximumSeries(): int
    {
        $series = 1;
        foreach ($this->labels as $allowed) {
            $series *= max(1, count($allowed) + 1);
        }

        return max(1, $series);
    }
}
