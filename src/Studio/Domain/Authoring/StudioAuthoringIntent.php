<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Authoring;

/**
 * App-native intent for opening Studio from a contextual authoring surface.
 *
 * This value is deliberately not a serialized Studio protocol document. It records only the host
 * decision that is already authoritative before a browser session exists: whether the Content
 * surface is creating a new item or editing one exact stored item.
 *
 * @since  2.0.0
 */
enum StudioAuthoringIntent: string
{
    /**
     * Begin a new item without treating a reusable type as a prerequisite.
     *
     * @since  2.0.0
     */
    case Create = 'create';

    /**
     * Continue one exact existing item at its current authorized revision.
     *
     * @since  2.0.0
     */
    case Edit = 'edit';
}
