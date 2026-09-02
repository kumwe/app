<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

use RuntimeException;

/**
 * The one failure type of the governance tooling.
 *
 * Every refusal the capability-index and core-growth tools raise carries three parts: the file the finding
 * belongs to, the rule that was broken and the fix an operator applies. A tool catches this type at its
 * boundary, prints the message under its own prefix and exits non-zero, so a governance failure is never a
 * stack trace and never a silent skip.
 *
 * @since  2.0.0
 */
final class GovernanceViolation extends RuntimeException
{
    /**
     * Build a violation from the file it concerns, the rule it breaks and the fix that clears it.
     *
     * @param   string  $file  Repository-relative path, or the record identifier, the finding belongs to.
     * @param   string  $rule  What was expected and what was found instead.
     * @param   string  $fix   The action that makes the check pass.
     *
     * @return  self  The violation with its message assembled as `<file>: <rule> Fix: <fix>`.
     *
     * @since   2.0.0
     */
    public static function at(string $file, string $rule, string $fix): self
    {
        return new self(sprintf('%s: %s Fix: %s', $file, rtrim($rule, '.') . '.', rtrim($fix, '.') . '.'));
    }
}
