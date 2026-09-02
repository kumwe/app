<?php

declare(strict_types=1);

namespace Kumwe\ExampleLegacy;

/**
 * Cases a legacy description may be formatted in.
 *
 * @since  2.0.0
 */
enum LegacyFormat: string
{
    /**
     * Upper-case the description.
     *
     * @since  2.0.0
     */
    case Upper = 'upper';

    /**
     * Lower-case the description.
     *
     * @since  2.0.0
     */
    case Lower = 'lower';
}
