<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Policy;

use Kumwe\Extension\Spi\BusinessSecurity\Policy\RecordPolicyPredicate;

/**
 * Explicit true or false leaf used when a policy intentionally covers every or no record.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicyConstant implements RecordPolicyPredicate
{
    /**
     * Create an explicit constant policy leaf.
     *
     * @param  bool  $value  Truth value this leaf always produces.
     *
     * @since  2.0.0
     */
    public function __construct(public bool $value)
    {
    }

    /**
     * Return the deterministic constant document used for policy digests.
     *
     * @return  array<string, mixed>  Canonical constant predicate document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => 'constant', 'value' => $this->value];
    }

    /**
     * Count this leaf as one policy operation.
     *
     * @return  int  Always one.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1;
    }

    /**
     * Measure this leaf as a one-level tree.
     *
     * @return  int  Always one.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1;
    }
}
