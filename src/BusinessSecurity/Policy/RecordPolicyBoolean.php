<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Policy;

use InvalidArgumentException;
use JsonException;

/**
 * Deterministic all-of or any-of composition over bounded policy children.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicyBoolean implements RecordPolicyPredicate
{
    /**
     * Canonically ordered child predicates.
     *
     * @var    list<RecordPolicyPredicate>
     * @since  2.0.0
     */
    public array $children;

    /**
     * Create a bounded, canonically ordered boolean composition.
     *
     * @param   RecordPolicyBooleanOperator  $operator  Whether every or any child must hold.
     * @param   list<RecordPolicyPredicate>  $children  One to sixteen child predicates.
     *
     * @throws  InvalidArgumentException  When the child list is empty, oversized, or contains another type.
     * @throws  JsonException  When a canonical child document cannot be encoded.
     *
     * @since   2.0.0
     */
    public function __construct(public RecordPolicyBooleanOperator $operator, array $children)
    {
        if ($children === [] || count($children) > 16 || !array_is_list($children)) {
            throw new InvalidArgumentException('A record-policy boolean node requires one to sixteen children.');
        }
        foreach ($children as $child) {
            if (!$child instanceof RecordPolicyPredicate) {
                throw new InvalidArgumentException('A record-policy boolean node contains an invalid child.');
            }
        }
        usort($children, static fn (RecordPolicyPredicate $left, RecordPolicyPredicate $right): int =>
            self::canonical($left) <=> self::canonical($right));
        $this->children = $children;
    }

    /**
     * Return the deterministic boolean document used for policy digests.
     *
     * @return  array<string, mixed>  Canonical boolean predicate document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'type' => 'boolean',
            'operator' => $this->operator->value,
            'children' => array_map(
                static fn (RecordPolicyPredicate $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    /**
     * Count this node and every child policy operation.
     *
     * @return  int  Positive operation count for the complete subtree.
     *
     * @since   2.0.0
     */
    public function operationCount(): int
    {
        return 1 + array_sum(array_map(
            static fn (RecordPolicyPredicate $child): int => $child->operationCount(),
            $this->children,
        ));
    }

    /**
     * Measure the deepest path through this boolean subtree.
     *
     * @return  int  Positive tree depth including this node.
     *
     * @since   2.0.0
     */
    public function depth(): int
    {
        return 1 + max(array_map(
            static fn (RecordPolicyPredicate $child): int => $child->depth(),
            $this->children,
        ));
    }

    /**
     * Encode a child for stable ordering.
     *
     * @param   RecordPolicyPredicate  $predicate  Child whose document is ordered.
     *
     * @return  string  Canonical JSON representation.
     *
     * @throws  JsonException  When encoding fails.
     *
     * @since   2.0.0
     */
    private static function canonical(RecordPolicyPredicate $predicate): string
    {
        return json_encode($predicate->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
