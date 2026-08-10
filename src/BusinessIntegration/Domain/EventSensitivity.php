<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

/**
 * Disclosure class carried by every durable business event.
 *
 * @since  2.0.0
 */
enum EventSensitivity: string
{
    /** Public information that may cross an explicitly configured public integration boundary. @since 2.0.0 */
    case PUBLIC = 'public';

    /** Ordinary installation data intended only for trusted internal consumers. @since 2.0.0 */
    case INTERNAL = 'internal';

    /** Restricted business data requiring a specifically authorised consumer. @since 2.0.0 */
    case RESTRICTED = 'restricted';

    /** Secret material that must never leave an explicitly secret-capable boundary. @since 2.0.0 */
    case SECRET = 'secret';

    /**
     * Report whether this class may be delivered through a boundary with the supplied ceiling.
     *
     * @param   self  $ceiling  Most sensitive class the boundary accepts.
     *
     * @return  bool  True when this value is no more sensitive than the ceiling.
     *
     * @since   2.0.0
     */
    public function allowedBy(self $ceiling): bool
    {
        return $this->rank() <= $ceiling->rank();
    }

    /**
     * Return the disclosure-order rank of this sensitivity level.
     *
     * @return  int  Stable sensitivity ordering.
     *
     * @since   2.0.0
     */
    private function rank(): int
    {
        return match ($this) {
            self::PUBLIC => 0,
            self::INTERNAL => 1,
            self::RESTRICTED => 2,
            self::SECRET => 3,
        };
    }
}
