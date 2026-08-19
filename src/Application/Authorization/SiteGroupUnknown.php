<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

/**
 * Raised when a group identifier resolves to no usable declaration.
 *
 * Both causes deny in the same direction, which is why they share one exception: the group was never
 * declared, or every site declared in it has been disabled. Neither may be answered with an empty group,
 * because an empty owning scope would leave the resources it owns visible to whoever asked next. The
 * ownership resolver converts this into `resource_site_unknown` so the gateway fails closed exactly as
 * it already does for a resource with no ownership row at all.
 *
 * @since  2.0.0
 */
final class SiteGroupUnknown extends \RuntimeException
{
    /**
     * Name the unresolved group in the operator-facing message.
     *
     * @param  string  $identifier  Group identifier that resolved to no enabled membership.
     *
     * @since  2.0.0
     */
    public function __construct(string $identifier)
    {
        parent::__construct(sprintf(
            'No enabled site group is declared under the identifier %s.',
            $identifier,
        ));
    }
}
