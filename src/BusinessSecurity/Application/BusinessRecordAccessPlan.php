<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessSecurity\Policy\RecordPolicySet;

/**
 * Immutable authorization decision consumed by every read of one business-record resource.
 *
 * The plan is already resolved for an actor, operation, definition, scope, membership and policy
 * revision. Persistence receives only its bounded policy tree and field/relationship allow-lists; it
 * never interprets roles or capabilities itself. The digest binds cursors and idempotent work to this
 * exact decision so a changed policy cannot resume an older view of the data.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordAccessPlan
{
    /**
     * Largest related-target set one valid business definition can declare.
     *
     * A definition admits 256 fields, all of which may be entity references, plus 128 relationships.
     * Keeping the access-plan bound aligned with that model prevents a valid definition from failing
     * authorization merely because its seventeenth target needs its own row and disclosure policy.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_RELATED_PLANS = 384;

    /**
     * Explicit target plans keyed by relation or entity-reference handle.
     *
     * @var    array<string, self>
     * @since  2.0.0
     */
    private array $related;

    /**
     * Canonically ordered action handles explicitly granted by this plan.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $actions;

    /**
     * Stable digest of every authorization input in this plan.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $digest;

    /**
     * @param   string                    $resourceIdentifier       Business-definition UUID this plan protects.
     * @param   string                    $operation                Dotted operation identifier being authorized.
     * @param   RecordPolicySet           $records                  Bounded row policy with default-deny semantics.
     * @param   FieldDisclosurePlan       $fields                   Explicit per-use field permissions.
     * @param   string                    $authorizationFingerprint Digest of actor, organization, membership
     *          and policy epoch.
     * @param   array<string, self>       $related                  Target plans keyed by relation or reference handle.
     * @param   list<string>              $actions                  Action handles explicitly permitted on
     *          matching rows.
     *
     * @throws  InvalidArgumentException  When identifiers, fingerprints, relations, actions, or graph
     *          bounds are invalid.
     * @throws  JsonException  When the canonical plan document cannot be encoded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $resourceIdentifier,
        public string $operation,
        public RecordPolicySet $records,
        public FieldDisclosurePlan $fields,
        public string $authorizationFingerprint,
        array $related = [],
        array $actions = [],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $resourceIdentifier) !== 1) {
            throw new InvalidArgumentException('A business-record access resource identifier is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9_]+)+$/D', $operation) !== 1) {
            throw new InvalidArgumentException('A business-record access operation is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $authorizationFingerprint) !== 1) {
            throw new InvalidArgumentException('A business-record authorization fingerprint is invalid.');
        }
        if (
            ($related !== [] && array_is_list($related))
            || count($related) > self::MAX_RELATED_PLANS
            || !array_is_list($actions)
            || count($actions) > 64
        ) {
            throw new InvalidArgumentException('A business-record access relation or action bound is invalid.');
        }
        foreach ($related as $handle => $plan) {
            if (
                !is_string($handle)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1
                || !$plan instanceof self
            ) {
                throw new InvalidArgumentException('A business-record related access plan is invalid.');
            }
            if ($plan->relationDepth() >= 2) {
                throw new InvalidArgumentException('A business-record access graph exceeds two relation hops.');
            }
        }
        foreach ($actions as $action) {
            if (!is_string($action) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $action) !== 1) {
                throw new InvalidArgumentException('A business-record action permission is invalid.');
            }
        }
        ksort($related, SORT_STRING);
        $actions = array_values(array_unique($actions));
        sort($actions, SORT_STRING);
        $this->related = $related;
        $this->actions = $actions;
        $this->digest = hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Return the target access plan for a relation or entity-reference handle.
     *
     * @param   string  $handle  Declared source field or relationship handle.
     *
     * @return  self|null  Explicit target plan, or null when traversal is denied.
     *
     * @since  2.0.0
     */
    public function related(string $handle): ?self
    {
        return $this->related[$handle] ?? null;
    }

    /**
     * Report whether an action handle is explicitly admitted by this plan.
     *
     * @param   string  $action  Declared business action handle.
     *
     * @return  bool  True only for a listed action.
     *
     * @since  2.0.0
     */
    public function allowsAction(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /**
     * Return the stable digest binding cursors and replay to this authorization decision.
     *
     * @return  string  Lowercase SHA-256 digest.
     *
     * @since  2.0.0
     */
    public function digest(): string
    {
        return $this->digest;
    }

    /**
     * Return the canonical plan document without its derived digest.
     *
     * @return  array<string, mixed>  Deterministic authorization decision.
     *
     * @since  2.0.0
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resourceIdentifier,
            'operation' => $this->operation,
            'records' => $this->records->toArray(),
            'fields' => $this->fields->toArray(),
            'authorization' => $this->authorizationFingerprint,
            'related' => array_map(static fn (self $plan): array => $plan->toArray(), $this->related),
            'actions' => $this->actions,
        ];
    }

    /**
     * Measure the deepest related-plan chain below this plan.
     *
     * @return  int  Zero for a leaf; otherwise the longest edge count.
     *
     * @since  2.0.0
     */
    private function relationDepth(): int
    {
        if ($this->related === []) {
            return 0;
        }

        return 1 + max(array_map(static fn (self $plan): int => $plan->relationDepth(), $this->related));
    }
}
