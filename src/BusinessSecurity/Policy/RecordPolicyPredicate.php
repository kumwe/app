<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Policy;

/**
 * One typed node in the declarative record-policy language.
 *
 * Implementations expose only canonical data and structural measurements. They cannot carry callbacks,
 * SQL, service names, or other executable input, which lets the same tree be interpreted in memory and
 * compiled by a persistence adapter without trusting policy-authored text.
 *
 * @since  2.0.0
 */
interface RecordPolicyPredicate
{
    /**
     * Return the deterministic document used for policy digests.
     *
     * @return  array<string, mixed>  Canonical predicate document.
     *
     * @since  2.0.0
     */
    public function toArray(): array;

    /**
     * Count this node and every descendant.
     *
     * @return  int  Positive operation count.
     *
     * @since  2.0.0
     */
    public function operationCount(): int;

    /**
     * Measure the deepest path through this node.
     *
     * @return  int  Positive tree depth.
     *
     * @since  2.0.0
     */
    public function depth(): int;
}
