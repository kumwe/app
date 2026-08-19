<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Domain;

use InvalidArgumentException;

/**
 * Verdict on one identity authorization question, carrying the reason that settled it.
 *
 * `AuthorizationPolicy` implementations and `AuthorizationService` answer with this rather than a bare
 * boolean, because a refusal is only actionable once you know which rule produced it: `user.inactive`
 * and `policy.no_allowance` come from the service itself, while `role.grant` and anything else comes
 * from the policy that spoke. The reason is therefore a stable machine token for logs and assertions,
 * validated on construction as a lowercase identifier and never a sentence meant for a person.
 * Instances exist only through `allow()` and `deny()`, so a decision is always one or the other.
 *
 * This is the identity-layer verdict, distinct from the namesake in
 * `Kumwe\App\Application\Authorization`, which records the wider gateway outcome and also names the
 * versioned policy that reached it.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationDecision
{
    /**
     * Bind a verdict to the reason token that justifies it.
     *
     * Private so that every instance comes from `allow()` or `deny()`, which makes this the single
     * point the reason token is checked.
     *
     * @param   bool    $allowed  True for an allowance, false for a denial.
     * @param   string  $reason   Stable token naming the rule that decided, such as `role.grant`.
     *
     * @throws  InvalidArgumentException  When the reason is not a lowercase identifier of 1 to 127
     *          characters beginning with a letter.
     *
     * @since   2.0.0
     */
    private function __construct(
        private bool $allowed,
        private string $reason,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('An authorization reason must be a stable lowercase identifier.');
        }
    }

    /**
     * Record that a rule permits the request.
     *
     * @param   string  $reason  Token naming the rule that allowed it; the default suits a caller with
     *          nothing more specific to say.
     *
     * @return  self  An allowance carrying that reason.
     *
     * @throws  InvalidArgumentException  When the reason is not a stable lowercase identifier.
     *
     * @since   2.0.0
     */
    public static function allow(string $reason = 'policy.allowed'): self
    {
        return new self(true, $reason);
    }

    /**
     * Record that a rule refuses the request.
     *
     * A denial from any single policy settles the whole decision in `AuthorizationService` and cannot
     * be overridden by a later allowance, so a policy with merely nothing to say abstains with null
     * instead of calling this.
     *
     * @param   string  $reason  Token naming the rule that refused it; the default suits a caller with
     *          nothing more specific to say.
     *
     * @return  self  A denial carrying that reason.
     *
     * @throws  InvalidArgumentException  When the reason is not a stable lowercase identifier.
     *
     * @since   2.0.0
     */
    public static function deny(string $reason = 'policy.denied'): self
    {
        return new self(false, $reason);
    }

    /**
     * Whether this decision permits the request it answers.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Whether this decision refuses the request it answers.
     *
     * `AuthorizationService` reads this while resolving its policies and returns the decision
     * immediately on the first true answer, leaving the remaining policies unconsulted.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function isDenied(): bool
    {
        return !$this->allowed;
    }

    /**
     * The token naming the rule that settled the decision, for audit records and test assertions.
     *
     * @return  string  A lowercase identifier such as `role.grant` or `policy.no_allowance`.
     *
     * @since   2.0.0
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
