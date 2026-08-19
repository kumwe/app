<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;

/**
 * One bounded resource selector inside a registered resource policy.
 *
 * A target always names one resource type. An empty identifier list selects every member and the
 * collection of that type; a non-empty list selects only the exact identifiers it contains. Keeping
 * the selector typed prevents policies from smuggling regular expressions or executable predicates
 * into the base action/resource registry.
 *
 * @since  2.0.0
 */
final readonly class ResourcePolicyTarget
{
    /**
     * Exact resource identifiers selected by this target; empty means every identifier.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $identifiers;

    /**
     * Validate one resource type and its optional exact identifier allowlist.
     *
     * @param   string            $type         Resource family the selector covers.
     * @param   iterable<string>  $identifiers  Exact identifiers, or an empty iterable for the whole family.
     *
     * @throws  InvalidArgumentException  When the type, an identifier, or the list bound is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(public string $type, iterable $identifiers = [])
    {
        AuthorizationResource::collection($type);
        $values = [];
        foreach ($identifiers as $identifier) {
            $value = AuthorizationResource::item($type, $identifier)->identifier();
            if ($value === '*') {
                throw new InvalidArgumentException('A resource-policy target uses an empty list for all identifiers.');
            }
            $values[$value] = true;
        }
        if (count($values) > 128) {
            throw new InvalidArgumentException('A resource-policy target may name at most 128 identifiers.');
        }
        ksort($values, SORT_STRING);
        $this->identifiers = array_keys($values);
    }

    /**
     * Whether this selector covers the requested authorization resource.
     *
     * @param   AuthorizationResource  $resource  Resource being evaluated.
     *
     * @return  bool  True when the type and, when bounded, the identifier match.
     *
     * @since   2.0.0
     */
    public function matches(AuthorizationResource $resource): bool
    {
        return $resource->type() === $this->type
            && ($this->identifiers === [] || in_array($resource->identifier(), $this->identifiers, true));
    }

    /**
     * Whether two selectors could match the same resource.
     *
     * Registration uses this to reject ambiguous action/resource bindings. A whole-family selector
     * overlaps every selector of its type; two bounded selectors overlap when they share an identifier.
     *
     * @param   self  $other  Selector to compare with this one.
     *
     * @return  bool  True when at least one authorization resource would satisfy both selectors.
     *
     * @since   2.0.0
     */
    public function overlaps(self $other): bool
    {
        if ($this->type !== $other->type) {
            return false;
        }

        return $this->identifiers === []
            || $other->identifiers === []
            || array_intersect($this->identifiers, $other->identifiers) !== [];
    }

    /**
     * Export the deterministic selector shape used by manifests and diagnostics.
     *
     * @return  array{type: string, identifiers: list<string>}  Type and sorted exact identifiers.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'identifiers' => $this->identifiers];
    }
}
