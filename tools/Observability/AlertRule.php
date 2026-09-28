<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * One alerting rule as the rule file declares it, with its expression parsed.
 *
 * @since  2.0.0
 */
final readonly class AlertRule
{
    /**
     * Capture the rule.
     *
     * @param  string                 $group        Name of the rule group it belongs to.
     * @param  string                 $name         Alert name.
     * @param  string                 $expression   PromQL source.
     * @param  array<string, mixed>   $tree         Parsed expression.
     * @param  string                 $for          Pending duration, `0s` when absent.
     * @param  array<string, string>  $labels       Rule labels.
     * @param  array<string, string>  $annotations  Rule annotations.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $group,
        public string $name,
        public string $expression,
        public array $tree,
        public string $for,
        public array $labels,
        public array $annotations,
    ) {
    }

    /**
     * Report the rule's severity label.
     *
     * @return  string  `page` or `ticket` in a valid file; whatever the file says otherwise.
     *
     * @since   2.0.0
     */
    public function severity(): string
    {
        return $this->labels['severity'] ?? '';
    }

    /**
     * Convert the pending duration to seconds.
     *
     * @return  int  Seconds.
     *
     * @since   2.0.0
     */
    public function forSeconds(): int
    {
        return Duration::seconds($this->for);
    }
}
