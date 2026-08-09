<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

/**
 * Lifecycle state shared by capability and resource-policy definitions in the live registry.
 *
 * Active and deprecated definitions remain enforceable, so an owner can announce a replacement
 * without breaking grants in the same release. Disabled and retired definitions fail closed even
 * when a stale stored grant still names them. Removing an extension withdraws its definitions
 * altogether; these explicit states cover definitions retained for rollout or compatibility.
 *
 * @since  2.0.0
 */
enum AuthorizationDefinitionLifecycle: string
{
    /**
     * Definition is current and may take part in authorization decisions.
     *
     * @since  2.0.0
     */
    case Active = 'active';

    /**
     * Definition remains enforceable while callers migrate to its replacement.
     *
     * @since  2.0.0
     */
    case Deprecated = 'deprecated';

    /**
     * Definition is retained as metadata but cannot authorize an operation.
     *
     * @since  2.0.0
     */
    case Disabled = 'disabled';

    /**
     * Definition is historical and cannot authorize an operation.
     *
     * @since  2.0.0
     */
    case Retired = 'retired';

    /**
     * Whether the definition may participate in a live authorization decision.
     *
     * @return  bool  True for active and deprecated definitions; false otherwise.
     *
     * @since   2.0.0
     */
    public function enforceable(): bool
    {
        return $this === self::Active || $this === self::Deprecated;
    }
}
