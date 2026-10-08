<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use InvalidArgumentException;

/**
 * The one App-wide item-composition policy every reader of stored item layouts consults (App ADR 0025).
 *
 * The container shares a single instance, so the authoring session, the preview binding source and the public
 * renderer always decide alike, and every reusable Content type declares the same policy to Studio. Its default
 * is `StudioContentCompositionService::ITEM_COMPOSITION`. Constructing it with `denied` is the rollback: saves
 * refuse an item layout again, and the editor, preview and public page ignore stored item layouts, which are kept
 * byte for byte.
 *
 * @since  2.0.0
 */
final readonly class StudioItemCompositionPolicy
{
    /**
     * Policies the reusable Content type contract admits.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array POLICIES = ['denied', 'overrides'];

    /**
     * Bind one admitted item-composition policy.
     *
     * @param   string  $policy  `overrides` to let Save item keep an item's own layout, or `denied`.
     *
     * @throws  InvalidArgumentException  When the policy is not one the contract admits.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $policy = StudioContentCompositionService::ITEM_COMPOSITION,
    ) {
        if (!in_array($policy, self::POLICIES, true)) {
            throw new InvalidArgumentException('The item-composition policy is not admitted.');
        }
    }

    /**
     * Whether this policy lets an entry keep, preview and render its own layout.
     *
     * @return  bool  True only for the `overrides` policy.
     *
     * @since   2.0.0
     */
    public function allowsItemLayouts(): bool
    {
        return StudioContentCompositionService::itemLayoutsAllowed($this->policy);
    }
}
