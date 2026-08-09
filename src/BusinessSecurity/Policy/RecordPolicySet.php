<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Policy;

use InvalidArgumentException;
use JsonException;

/**
 * Bounded allow and deny predicates for one record resource.
 *
 * At least one allow must match and no deny may match. Consequently an empty allow list denies every
 * record, while a matching deny always overrides a matching allow.
 *
 * @since  2.0.0
 */
final readonly class RecordPolicySet
{
    /**
     * Canonically ordered predicates of which at least one must match.
     *
     * @var    list<RecordPolicyPredicate>
     * @since  2.0.0
     */
    public array $allows;

    /**
     * Canonically ordered predicates any one of which overrides an allow.
     *
     * @var    list<RecordPolicyPredicate>
     * @since  2.0.0
     */
    public array $denies;

    /**
     * Create a bounded, schema-validated allow and deny policy set.
     *
     * @param   RecordPolicySchema           $schema  Field vocabulary shared by compiler and evaluator.
     * @param   list<RecordPolicyPredicate>  $allows  Predicates granting access when one matches.
     * @param   list<RecordPolicyPredicate>  $denies  Predicates withholding access when one matches.
     *
     * @throws  InvalidArgumentException  When policy counts, total operations, depth, fields, or types exceed bounds.
     * @throws  JsonException  When canonical ordering cannot encode a predicate.
     *
     * @since   2.0.0
     */
    public function __construct(
        public RecordPolicySchema $schema,
        array $allows = [],
        array $denies = [],
    ) {
        if (!array_is_list($allows) || !array_is_list($denies) || count($allows) > 32 || count($denies) > 32) {
            throw new InvalidArgumentException('A record-policy set exceeds its allow or deny bound.');
        }
        $operations = 0;
        foreach ([...$allows, ...$denies] as $predicate) {
            if (!$predicate instanceof RecordPolicyPredicate) {
                throw new InvalidArgumentException('A record-policy set contains an invalid predicate.');
            }
            $schema->assertPredicate($predicate);
            $operations += $predicate->operationCount();
            if ($predicate->depth() > 8) {
                throw new InvalidArgumentException('A record-policy predicate exceeds eight levels.');
            }
        }
        if ($operations > 64) {
            throw new InvalidArgumentException('A record-policy set exceeds 64 operations.');
        }
        usort($allows, self::order(...));
        usort($denies, self::order(...));
        $this->allows = $allows;
        $this->denies = $denies;
    }

    /**
     * Evaluate this set over one record value map.
     *
     * @param   array<string, mixed>  $values  Stored values keyed by stable field handle.
     *
     * @return  bool  True only when an allow matches and no deny matches.
     *
     * @since   2.0.0
     */
    public function allows(array $values): bool
    {
        return (new RecordPolicyEvaluator())->allows($this, $values);
    }

    /**
     * Return the canonical policy document.
     *
     * @return  array<string, mixed>  Schema plus ordered allow and deny predicate documents.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema->toArray(),
            'allows' => array_map(static fn (RecordPolicyPredicate $item): array => $item->toArray(), $this->allows),
            'denies' => array_map(static fn (RecordPolicyPredicate $item): array => $item->toArray(), $this->denies),
        ];
    }

    /**
     * Order two predicates by canonical JSON.
     *
     * @param   RecordPolicyPredicate  $left   First predicate document.
     * @param   RecordPolicyPredicate  $right  Second predicate document.
     *
     * @return  int  Three-way canonical document comparison.
     *
     * @throws  JsonException  When a canonical predicate cannot be encoded.
     *
     * @since   2.0.0
     */
    private static function order(RecordPolicyPredicate $left, RecordPolicyPredicate $right): int
    {
        $encode = static fn (RecordPolicyPredicate $item): string => json_encode(
            $item->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return $encode($left) <=> $encode($right);
    }
}
