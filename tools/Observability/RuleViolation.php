<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use RuntimeException;

/**
 * A refusal from the observability rule, dashboard or drill tooling, naming what it concerns and why.
 *
 * @since  2.0.0
 */
final class RuleViolation extends RuntimeException
{
    /**
     * Build a violation from the subject it concerns and the rule it breaks.
     *
     * @param   string  $subject  File and line, alert name or dashboard panel the finding belongs to.
     * @param   string  $rule     What was expected and what was found instead.
     *
     * @return  self  The violation with its message assembled as `<subject>: <rule>.`.
     *
     * @since   2.0.0
     */
    public static function at(string $subject, string $rule): self
    {
        return new self(sprintf('%s: %s.', $subject, rtrim($rule, '.')));
    }
}
